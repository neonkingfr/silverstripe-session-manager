<?php

namespace SilverStripe\SessionManager\Model;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\RememberLoginHash;
use SilverStripe\Security\Security;
use SilverStripe\SessionManager\Security\LogInAuthenticationHandler;
use UAParser\Parser;

/**
 * Class LoginSession
 * @package SilverStripe\SessionManager\Model
 *
 * @property DBDatetime $LastAccessed
 * @property string $IPAddress
 * @property string $UserAgent
 * @property bool $Persistent
 * @method Member Member
 */
class LoginSession extends DataObject
{
    private static $db = [
        'LastAccessed' => 'DBDatetime',
        'IPAddress' => 'Varchar(45)',
        'UserAgent' => 'Text',
        'Persistent' => 'Boolean'
    ];

    private static $has_one = [
        'Member' => Member::class
    ];

    private static $belongs_to = [
        'LoginHash' => RememberLoginHash::class
    ];

    private static $indexes = [
        'LastAccessed' => true
    ];

    private static $table_name = 'LoginSession';

    private static $default_sort = 'LastAccessed DESC';

    private static $summary_fields = [
        'IPAddress' => 'IP Address',
        'LastAccessed' => 'Last Accessed',
        'Created' => 'Signed In',
        'FriendlyUserAgent' => 'User Agent'
    ];

    private static $searchable_fields = [
        'IPAddress',
    ];

    /**
     * The length of time a session can be inactive for before it is discarded and the
     * user is logged out
     *
     * @config
     * @var int
     */
    private static $default_session_lifetime = 3600;

    public function canCreate($member = null, $context = [])
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }

        // Allow extensions to overrule permissions
        $extended = $this->extendedCan(__FUNCTION__, $member, $context);
        if ($extended !== null) {
            return $extended;
        }

        // Must be logged in to act on login sessions
        if (!$member) {
            return false;
        }

        // Any user with access to SecurityAdmin can create a session
        // @todo Does this even make sense? When would you non-programatically create a session?
        return Permission::checkMember($member, 'CMS_ACCESS_SecurityAdmin');
    }

    public function canView($member = null)
    {
        return $this->handlePermission(__FUNCTION__, $member);
    }

    public function canEdit($member = null)
    {
        return $this->handlePermission(__FUNCTION__, $member);
    }

    public function canDelete($member = null)
    {
        return $this->handlePermission(__FUNCTION__, $member);
    }

    /**
     * @param string $fn Permission method being called - one of canView/canEdit/canDelete
     * @param mixed $member
     * @return bool
     */
    public function handlePermission($fn, $member)
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }

        // Allow extensions to overrule permissions
        $extended = $this->extendedCan($fn, $member);
        if ($extended !== null) {
            return $extended;
        }

        // Must be logged in to act on login sessions
        if (!$member) {
            return false;
        }

        // Members can manage their own sessions
        if ($this->ID == $member->ID) {
            return true;
        }

        // Access to SecurityAdmin implies session management permissions
        return Permission::checkMember($member, 'CMS_ACCESS_SecurityAdmin');
    }

    /**
     * @param Member $member
     * @param HTTPRequest $request
     * @return static|null
     */
    public static function find(Member $member, HTTPRequest $request)
    {
        $session = static::get()->filter([
            'IPAddress' => $request->getIP(),
            'UserAgent' => $request->getHeader('User-Agent'),
            'MemberID' => $member->ID,
            'Persistent' => 1
        ])->first();

        return $session;
    }

    /**
     * @param Member $member
     * @param boolean $persistent
     * @param HTTPRequest $request
     * @return static
     */
    public static function generate(Member $member, bool $persistent, HTTPRequest $request)
    {
        $session = static::create()->update([
            'LastAccessed' => DBDatetime::now()->Rfc2822(),
            'IPAddress' => $request->getIP(),
            'UserAgent' => $request->getHeader('User-Agent'),
            'MemberID' => $member->ID,
            'Persistent' => intval($persistent)
        ]);
        $session->write();

        return $session;
    }

    /**
     * @return string
     */
    public function getFriendlyUserAgent()
    {
        if (!$this->UserAgent) {
            return '';
        }

        $parser = Parser::create();
        $result = $parser->parse($this->UserAgent);

        return sprintf('%s on %s', $result->ua->family, $result->os->toString());
    }

    /**
     * @param Member|null $member
     * @param HTTPRequest|null $request
     * @return LoginSession|null
     */
    public static function getCurrentLoginSession(Member $member = null, HTTPRequest $request = null)
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }

        // Fall back to retrieving request from current Controller if available
        if ($request === null) {
            if (!Controller::has_curr()) {
                throw new InvalidArgumentException(
                    "A HTTPRequest is required to check if this is the currently used LoginSession."
                );
            }

            $request = Controller::curr()->getRequest();
        }

        $loginHandler = Injector::inst()->get(LogInAuthenticationHandler::class);
        $loginSessionID = $request->getSession()->get($loginHandler->getSessionVariable());
        $loginSession = LoginSession::get()->byID($loginSessionID);
        return $loginSession;
    }

    /**
     * @param Member|null $member
     * @param HTTPRequest|null $request
     * @return static|null
     */
    public function isCurrent(Member $member = null, HTTPRequest $request = null)
    {
        $currentLoginSession = static::getCurrentLoginSession($member, $request);
        if (!$currentLoginSession) {
            return false;
        }

        return $this->ID === $currentLoginSession->ID;
    }

    /**
     * @param Member $member
     * @return mixed
     */
    public static function getCurrentSessions(Member $member)
    {
        $sessionLifetime = static::getSessionLifetime();
        $maxAge = DBDatetime::now()->getTimestamp() - $sessionLifetime;
        $currentSessions = $member->LoginSessions()->filterAny([
            'Persistent' => 1,
            'LastAccessed:GreaterThan' => date('Y-m-d H:i:s', $maxAge)
        ]);
        return $currentSessions;
    }

    /**
     * @return int
     */
    public static function getSessionLifetime()
    {
        if ($lifetime = Session::config()->get('timeout')) {
            return $lifetime;
        }

        return LoginSession::config()->get('default_session_lifetime');
    }
}

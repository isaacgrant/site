<?php

require_once 'Site/SiteSessionModule.php';
require_once 'Site/dataobjects/SiteAccount.php';
require_once 'Site/dataobjects/SiteAccountLoginSession.php';
require_once 'SwatDB/SwatDBClassMap.php';
require_once 'Swat/SwatDate.php';
require_once 'Swat/SwatForm.php';

/**
 * Web application module for sessions with accounts.
 *
 * Provides methods for account login/logout and accessing dataobjects in the
 * session.
 *
 * @package   Site
 * @copyright 2006-2013 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
class SiteAccountSessionModule extends SiteSessionModule
{
	// {{{ protected properties

	/**
	 * @var array
	 */
	protected $login_callbacks = array();

	/**
	 * @var array
	 */
	protected $logout_callbacks = array();

	// }}}
	// {{{ public function init()

	/**
	 * Initializes this session module
	 */
	public function init()
	{
		parent::init();

		$cookie = $this->app->getModule('SiteCookieModule');
		$config = $this->app->getModule('SiteConfigModule');

		// If persistent logins are enabled and a login cookie is present,
		// try to log in.
		if ($config->account->persistent_login_enabled &&
			!$this->isLoggedIn() &&
			isset($cookie->login)) {
			$this->loginByTag($cookie->login);
		}
	}

	// }}}
	// {{{ public function depends()

	/**
	 * Gets the module features this module depends on
	 *
	 * The site account session module depends on the SiteDatabaseModule
	 * feature.
	 *
	 * @return array an array of {@link SiteApplicationModuleDependency}
	 *                        objects defining the features this module
	 *                        depends on.
	 */
	public function depends()
	{
		$depends = parent::depends();
		$depends[] = new SiteApplicationModuleDependency('SiteDatabaseModule');
		return $depends;
	}

	// }}}
	// {{{ public function login()

	/**
	 * Logs the current session into a {@link SiteAccount}
	 *
	 * @param string $email the email address of the account to login.
	 * @param string $password the password of the account to login.
	 * @param boolean $regenerate_id optional. Whether or not to regenerate
	 *                                the session identifier on successful
	 *                                login. Defaults to
	 *                                {@link SiteSessionModule::REGENERATE_ID}.
	 *
	 * @return boolean true if the account was successfully logged in and false
	 *                       if the email/password pair did not match an
	 *                       account.
	 */
	public function login($email, $password,
		$regenerate_id = SiteSessionModule::REGENERATE_ID)
	{
		if ($this->isLoggedIn())
			$this->logout();

		$account = $this->getNewAccountObject();

		$instance = ($this->app->hasModule('SiteMultipleInstanceModule')) ?
			$this->app->instance->getInstance() : null;

		if ($account->loadWithEmail($email, $instance) &&
			$account->isCorrectPassword($password)) {

			// No Crypt?! Crypt!
			if (!$account->isCryptPassword()) {
				$account->setPassword($password);
				$account->save();
			}

			$this->activate();

			$this->account = $account;

			if ($regenerate_id) {
				$this->regenerateId();
			}

			$this->setAccountCookie();
			$this->runLoginCallbacks();

			// save last login date
			$now = new SwatDate();
			$now->toUTC();
			$this->account->updateLastLoginDate(
				$now,
				$this->app->getRemoteIP(15)
			);

			$this->setLoginSession();
		}

		return $this->isLoggedIn();
	}

	// }}}
	// {{{ public function loginById()

	/**
	 * Logs the current session into a {@link SiteAccount} using an id
	 *
	 * @param integer $id The id of the {@link SiteAccount} to log into.
	 * @param boolean $regenerate_id optional. Whether or not to regenerate
	 *                                the session identifier on successful
	 *                                login. Defaults to
	 *                                {@link SiteSessionModule::REGENERATE_ID}.
	 *
	 * @return boolean true if the account was successfully logged in and false
	 *                       if the id does not match an account.
	 */
	public function loginById($id,
		$regenerate_id = SiteSessionModule::REGENERATE_ID)
	{
		if ($this->isLoggedIn())
			$this->logout();

		$account = $this->getNewAccountObject();

		if ($account->load($id)) {
			$this->activate();
			$this->account = $account;

			if ($regenerate_id) {
				$this->regenerateId();
			}

			$this->setAccountCookie();
			$this->runLoginCallbacks();

			// save last login date
			$now = new SwatDate();
			$now->toUTC();
			$this->account->updateLastLoginDate(
				$now,
				$this->app->getRemoteIP(15)
			);

			$this->setLoginSession();
		}

		return $this->isLoggedIn();
	}

	// }}}
	// {{{ public function loginByAccount()

	/**
	 * Logs the current session into the specified {@link SiteAccount}
	 *
	 * @param SiteAccount $account The account to log into.
	 * @param boolean $regenerate_id optional. Whether or not to regenerate
	 *                                the session identifier on successful
	 *                                login. Defaults to
	 *                                {@link SiteSessionModule::REGENERATE_ID}.
	 * @param boolean $new_login_session optional. Whether or not this is a new
	 *                                    login session. If true it saves the
	 *                                    login session upon successful login.
     *                                    Defaults to true.
	 *
	 * @return boolean true.
	 */
	public function loginByAccount(SiteAccount $account,
		$regenerate_id = SiteSessionModule::REGENERATE_ID,
		$new_login_session = true)
	{
		if ($this->isLoggedIn())
			$this->logout();

		$this->activate();
		$this->account = $account;

		if ($regenerate_id) {
			$this->regenerateId();
		}

		$this->setAccountCookie();
		$this->runLoginCallbacks();

		// save last login date
		$now = new SwatDate();
		$now->toUTC();
		$this->account->updateLastLoginDate(
			$now,
			$this->app->getRemoteIP(15)
		);

		if ($new_login_session) {
			$this->setLoginSession();
		}

		return true;
	}

	// }}}
	// {{{ public function loginByTag()

	/**
	 * Logs the current session into a {@link SiteAccount} using a persistent
	 * login tag
	 *
	 * @param string $tag the persistent login tag value to use.
	 * @param boolean $regenerate_id optional. Whether or not to regenerate
	 *                                the session identifier on successful
	 *                                login. Defaults to
	 *                                {@link SiteSessionModule::REGENERATE_ID}.
	 *
	 * @return boolean true if the account was successfully logged in and false
	 *                       if the tag does not match an account.
	 */
	public function loginByTag($tag,
		$regenerate_id = SiteSessionModule::REGENERATE_ID)
	{
		$logged_in = false;

		$instance = ($this->app->hasModule('SiteMultipleInstanceModule')) ?
			$this->app->instance->getInstance() : null;

		$account = $this->getNewAccountObject();
		if ($account->loadByLoginTag($tag, $instance)) {

			// log in account
			$logged_in = $this->loginByAccount(
				$account,
				$regenerate_id,
				false // don't save a new login session.
			);

			// Update login tag session id and date. Set login session as clean
			// since account was just loaded.
			$login_date = new SwatDate();
			$login_date->toUTC();

			$sql = sprintf(
				'update AccountLoginSession
				set session_id = %s, login_date = %s
				where tag = %s',
				$this->app->db->quote($this->getSessionId(), 'text'),
				$this->app->db->quote($login_date->getISO8601(), 'date'),
				$this->app->db->quote($tag, 'text')
			);

			if ($instance instanceof SiteMultipleInstanceModule) {
				$sql.= sprintf(
					' and instance = %s',
					$this->app->db->quote($instance->id, 'integer')
				);
			}

			SwatDB::exec($this->app->db, $sql);
		} else {
			// clear the cookie if the restore fails.
			$this->unsetLoginCookie();
		}

		return $logged_in;
	}

	// }}}
	// {{{ public function logout()

	/**
	 * Logs the current user out
	 */
	public function logout()
	{
		$this->unsetLoginSession();
		$this->unsetLoginCookie();

		// Check isActive() instead of isLoggedIn() because we sometimes
		// call logout() to clear registered session objects even when
		// users are not logged in.
		if ($this->isActive()) {
			unset($this->account);
			unset($this->_authentication_token);
			parent::unsetRegisteredObjects();
		}

		$this->runLogoutCallbacks();
		$this->removeAccountCookie();
	}

	// }}}
	// {{{ public function isLoggedIn()

	/**
	 * Checks the current user's logged-in status
	 *
	 * @return boolean true if user is logged in, false if the user is not
	 *                  logged in.
	 */
	public function isLoggedIn()
	{
		if (!$this->isActive()) {
			return false;
		}

		if (!isset($this->account)) {
			return false;
		}

		if (!$this->account instanceof SiteAccount) {
			return false;
		}

		if ($this->account->id === null) {
			return false;
		}

		return true;
	}

	// }}}
	// {{{ public function isAccountDirty()

	/**
	 * Gets whether or not the logged account in this session needs to be
	 * reloaded from the database
	 *
	 * If no account is logged in, false is returned.
	 *
	 * @return boolean whether or not the logged account in this session needs
	 *                  to be reloaded from the database.
	 */
	public function isAccountDirty()
	{
		$is_dirty = false;

		if ($this->isLoggedIn()) {
			$sql = sprintf(
				'select dirty from AccountLoginSession
				where session_id = %s',
				$this->app->db->quote($this->getSessionId(), 'text')
			);

			$is_dirty = SwatDB::queryOne(
				$this->app->db,
				$sql,
				array('boolean')
			);
		}

		return $is_dirty;
	}

	// }}}
	// {{{ public function getAccountId()

	/**
	 * Retrieves the current account Id
	 *
	 * @return integer the current account ID, or null if not logged in.
	 */
	public function getAccountId()
	{
		if (!$this->isLoggedIn())
			return null;

		return $this->account->id;
	}

	// }}}
	// {{{ public function registerLoginCallback()

	/**
	 * Registers a callback function that is executed when a successful session
	 * login is performed
	 *
	 * @param callback $callback the callback to call when a successful login
	 *                            is performed.
	 * @param array $parameters optional. The paramaters to pass to the
	 *                           callback. Use an empty array for no parameters.
	 */
	public function registerLoginCallback($callback,
		array $parameters = array())
	{
		if (!is_callable($callback))
			throw new SiteException('Cannot register invalid callback.');

		$this->login_callbacks[] = array(
			'callback' => $callback,
			'parameters' => $parameters
		);
	}

	// }}}
	// {{{ public function registerLogoutCallback()

	/**
	 * Registers a callback function that is executed after a logout is
	 * performed
	 *
	 * @param callback $callback the callback to call when a logout is
	 *                            performed.
	 * @param array $parameters optional. The paramaters to pass to the
	 *                           callback. Use an empty array for no parameters.
	 */
	public function registerLogoutCallback($callback,
		array $parameters = array())
	{
		if (!is_callable($callback))
			throw new SiteException('Cannot register invalid callback.');

		$this->logout_callbacks[] = array(
			'callback' => $callback,
			'parameters' => $parameters
		);
	}

	// }}}
	// {{{ public function setLoginCookie()

	/**
	 * Sets the cookie used for persistent logins
	 *
	 * Persistent login cookie may only be set if this session is logged in.
	 *
	 * @return true if the cookie was set, false if it was not.
	 */
	public function setLoginCookie()
	{
		if (!$this->isLoggedIn()) {
			return false;
		}

		$cookie = $this->app->getModule('SiteCookieModule');
		$config = $this->app->getModule('SiteConfigModule');

		$now = new SwatDate();
		$now->toUTC();
		$now->addSeconds($config->account->persistent_login_time);

		$transaction = new SwatDBTransaction($this->app->db);
		try {
			$tag    = $this->generateLoginTag();
			$expiry = $now->getTimestamp();

			$cookie->setCookie('login', $tag, $expiry);
			$transaction->commit();
		} catch (Exception $e) {
			$transaction->rollback();
			$this->unsetLoginCookie();
			throw $e;
		}

		return true;
	}

	// }}}
	// {{{ public function generateLoginTag()

	public function generateLoginTag()
	{
		$tag = base64_encode(SwatString::getSalt(16));

		$sql = sprintf(
			'update AccountLoginSession
			set tag = %s
			where session_id = %s and account = %s',
			$this->app->db->quote($tag, 'text'),
			$this->app->db->quote($this->getSessionId(), 'text'),
			$this->app->db->quote($this->account->id, 'integer')
		);

		SwatDB::exec($this->app->db, $sql);

		return $tag;
	}

	// }}}
	// {{{ public function unsetLoginCookie()

	public function unsetLoginCookie()
	{
		$cookie = $this->app->getModule('SiteCookieModule');
		$cookie->removeCookie('login');
	}

	// }}}
	// {{{ public function setLoginSession()

	/**
	 * Sets the cookie used for persistent logins
	 *
	 * Persistent login cookie may only be set if this session is logged in.
	 *
	 * @return true if the cookie was set, false if it was not.
	 */
	public function setLoginSession()
	{
		if (!$this->isLoggedIn()) {
			return false;
		}

		$now = new SwatDate();
		$now->toUTC();

		$class = SwatDBClassMap::get('SiteAccountLoginSession');
		$login_session = new $class();

		$login_session->account    = $this->account;
		$login_session->session_id = $this->getSessionId();
		$login_session->createdate = $now;
		$login_session->login_date = $now;
		$login_session->ip_address = $this->app->getRemoteIP(15);

		if (isset($_SERVER['HTTP_USER_AGENT'])) {

			$user_agent = $_SERVER['HTTP_USER_AGENT'];

			// Filter bad character encoding. If invalid, assume ISO-8859-1
			// encoding and convert to UTF-8.
			if (!SwatString::validateUtf8($user_agent)) {
				$user_agent = iconv('ISO-8859-1', 'UTF-8', $user_agent);
			}

			// Only save if the user-agent was successfully converted to
			// UTF-8.
			if ($user_agent !== false) {
				// set max length based on database field length
				$user_agent = substr($user_agent, 0, 255);
				$login_session->user_agent = $user_agent;
			}

		}

		$login_session->setDatabase($this->app->db);
		$login_session->save();

		return true;
	}

	// }}}
	// {{{ public function unsetLoginSession()

	public function unsetLoginSession()
	{
		if ($this->isLoggedIn()) {
			$sql = sprintf(
				'delete from AccountLoginSession
				where session_id = %s and account = %s',
				$this->app->db->quote($this->getSessionId(), 'text'),
				$this->app->db->quote($this->account->id, 'integer')
			);

			SwatDB::exec($this->app->db, $sql);
		}
	}

	// }}}
	// {{{ public function reloadAccount()

	/**
	 * Reloads the logged in account in this session from the database
	 *
	 * If no account is logged in, no action is performed.
	 */
	public function reloadAccount()
	{
		if ($this->isLoggedIn()) {
			$sql = sprintf(
				'update AccountLoginSession set dirty = %s
				where session_id = %s',
				$this->app->db->quote(false, 'boolean'),
				$this->app->db->quote($this->getSessionId(), 'text')
			);

			SwatDB::exec($this->app->db, $sql);

			$this->account->load($this->getAccountId());
		}
	}

	// }}}
	// {{{ protected function startSession()

	/**
	 * Starts a session
	 */
	protected function startSession()
	{
		parent::startSession();

		if (isset($this->_authentication_token)) {
			SwatForm::setAuthenticationToken($this->_authentication_token);
		}

		if ($this->isAccountDirty()) {
			$this->reloadAccount();
		}
	}

	// }}}
	// {{{ protected function getNewAccountObject()

	protected function getNewAccountObject()
	{
		$class_name = SwatDBClassMap::get('SiteAccount');
		$account = new $class_name();
		$account->setDatabase($this->app->db);

		return $account;
	}

	// }}}
	// {{{ protected function runLoginCallbacks()

	protected function runLoginCallbacks()
	{
		foreach ($this->login_callbacks as $login_callback) {
			$callback = $login_callback['callback'];
			$parameters = $login_callback['parameters'];
			call_user_func_array($callback, $parameters);
		}
	}

	// }}}
	// {{{ protected function runLogoutCallbacks()

	protected function runLogoutCallbacks()
	{
		foreach ($this->logout_callbacks as $logout_callback) {
			$callback = $logout_callback['callback'];
			$parameters = $logout_callback['parameters'];
			call_user_func_array($callback, $parameters);
		}
	}

	// }}}
	// {{{ protected function setAccountCookie()

	protected function setAccountCookie()
	{
		if (!$this->app->hasModule('SiteCookieModule')) {
			return;
		}

		$cookie = $this->app->getModule('SiteCookieModule');
		$config = $this->app->getModule('SiteConfigModule');

		if ($config->account->restore_cookie_enabled) {
			$cookie->setCookie('account_id', $this->getAccountId());
		}
	}

	// }}}
	// {{{ protected function removeAccountCookie()

	protected function removeAccountCookie()
	{
		if (!$this->app->hasModule('SiteCookieModule')) {
			return;
		}

		$cookie = $this->app->getModule('SiteCookieModule');
		$cookie->removeCookie('account_id');
	}

	// }}}

	// deprecated
	// {{{ public function registerDataObject()

	/**
	 * Registers an object class for a session variable
	 *
	 * @param string $name the name of the session variable.
	 * @param string $class the object class name.
	 * @param boolean $destroy_on_logout whether or not to destroy the object
	 *                                    on logout.
	 *
	 * @deprecated Use {@link SiteSessionModule::registerObject()} instead.
	 */
	public function registerDataObject($name, $class,
		$destroy_on_logout = true)
	{
		parent::registerObject($name, $class, $destroy_on_logout);
	}

	// }}}
	// {{{ public function unsetRegisteredDataObjects()

	/**
	 * Unsets objects registered in the session and marked as
	 * destroy-on-logout
	 *
	 * @deprecated Use {@link SiteSessionModule::usetRegisteredObjects()}
	 *             instead.
	 */
	public function unsetRegisteredDataObjects()
	{
		parent::unsetRegisteredObjects();
	}

	// }}}
}

?>

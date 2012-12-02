<?php

/**
 * @package Defensio
 */
class Defensio extends Plugin
{
	/**
	 * Use this constant to put Defensio comment auditing into testing mode. You can force it to
	 * return spam or ham and the spaminess value (in the range 0 to 1)
	 * @example "spam,0.5668" or "ham,0.3669"
	 */
	const TEST_FORCE = FALSE;

	const MAX_RETRIES = 10;
	const RETRY_INTERVAL = 30;
	const COMMENT_STATUS_QUEUED = 9;

	const OPTION_API_KEY = 'defensio__api_key';
	const OPTION_FLAG_SPAMINESS = 'defensio__spaminess_flag';
	const OPTION_ANNOUNCE_POSTS = 'defensio__announce_posts';
	const OPTION_AUTO_APPROVE = 'defensio__auto_approve';

	private $defensio;

	/**
	 * spl_autoload implementation to load the DefensioAPI
	 * @param string $class the classname to load
	 */
	public static function autoload( $class )
	{
		static $classes = array( 'defensioapi', 'defensionode', 'defensioparams', 'defensioresponse' );
		static $loaded = FALSE;

		if ( !$loaded && in_array( strtolower($class), $classes ) !== FALSE ) {
			require "defensioapi.php";
			$loaded = true;
		}
	}

	/**
	 * Set the priority of 'action_comment_insert_before' to 1, so we are close to first to run
	 * before Comment insertion.
	 * @return array The customized priority
	 */
	public function set_priorities()
	{
		return array(
			'action_comment_insert_before' => 1
		);
	}

	/**
	 * Setup defaults on activation. Don't overwrite API key if it's already there.
	 */
	public function action_plugin_activation()
	{
		Session::notice( _t('Please set your Defensio API Key in the configuration.', 'defensio') );
		if ( !Options::get(self::OPTION_API_KEY) ) {
			Options::set( self::OPTION_API_KEY, '' );
		}
		Options::set(self::OPTION_FLAG_SPAMINESS, 0);
		Options::set(self::OPTION_ANNOUNCE_POSTS, 'yes');
		Options::set(self::OPTION_AUTO_APPROVE, 'no');
	}

	/**
	 * Implement the simple plugin configuration.
	 * @return FormUI The configuration form
	 */
	public function configure()
	{
		$ui = new FormUI( 'defensio' );

		// Add a text control for the address you want the email sent to
		$api_key = $ui->append(
				'text',
				'api_key',
				'option:' . self::OPTION_API_KEY,
				_t('Defensio API Key: ', 'defensio')
			);
		$api_key->add_validator( 'validate_required' );
		$api_key->add_validator( array( $this, 'validate_api_key' ) );

		// min spamines flag
		$spaminess = $ui->append(
				'select',
				'min_spaminess',
				'option:' . self::OPTION_FLAG_SPAMINESS,
				_t('Minimum Spaminess to Flag as Spam (%): ', 'defensio')
			);
		$spaminess->options = array_combine( range(0, 100, 5), range(0, 100, 5) );
		$spaminess->add_validator( 'validate_required' );

		// using yes/no is not ideal but it's what we got :(
		$announce_posts = $ui->append(
				'select',
				'announce_posts',
				'option:' . self::OPTION_ANNOUNCE_POSTS,
				_t('Announce New Posts To Defensio: ', 'defensio')
			);
		$announce_posts->options = array( 'yes' => _t('Yes', 'defensio'), 'no' => _t('No', 'defensio') );
		$announce_posts->add_validator( 'validate_required' );

		$auto_approve = $ui->append( 'select',
				'auto_approve',
				'option:' . self::OPTION_AUTO_APPROVE,
				_t('Automatically Approve Non-Spam Comments: ', 'defensio')
			);
		$auto_approve->options = array( 'no' => _t('No', 'defensio'), 'yes' => _t('Yes', 'defensio') );
		$auto_approve->add_validator( 'validate_required' );

		$register = $ui->append(
				'static',
				'register',
				'<a href="http://defensio.com/signup">' . _t('Get A New Defensio API Key.', 'defensio') . '</a>'
			);

		$ui->append( 'submit', 'save', _t( 'Save', 'defensio' ) );
		$ui->on_success( array($this, 'formui_submit') );
		return $ui->get();
	}

	/**
	 * Handle the form submition and save options
	 * @param FormUI $form The FormUI that was submitted
	 */
	public function formui_submit( FormUI $form )
	{
		Session::notice( _t('Defensio options saved.', 'defensio') );
		$form->save();
	}

	/**
	 * FormUI validator to validate the entered API key with Defensio.
	 * @param string $key The API key to validate.
	 * @return array The error message if validation failed, or blank array if successful.
	 */
	public function validate_api_key( $key )
	{
		try {
			DefensioAPI::validate_api_key( $key, Site::get_url( 'habari' ) );
		}
		catch ( Exception $e ) {
			return array(
				_t('Sorry, the Defensio API key <b>%s</b> is invalid. Please check to make sure the
					key is entered correctly and is <b>registered for this site (%s)</b>.  Defensio
					said: "%s"',
				array( $key, Site::get_url( 'habari' ), $e->getMessage() ),
				'defensio')
			);
		}
		return array();
	}

	/**
	 * Setup the DefensioAPI on Habari initialization.
	 * @todo move text domain loading to only admin.
	 */
	public function action_init()
	{
		$this->defensio = new DefensioAPI( Options::get( self::OPTION_API_KEY ), Site::get_url( 'habari' ) );
		$this->load_text_domain( 'defensio' );
	}

	/**
	 * Return a list of blocks that can be used for the dashboard
	 * @param array $block_list An array of block names, indexed by unique string identifiers
	 * @return array The altered array
	 */
	public function filter_dashboard_block_list( Array $block_list )
	{
		$block_list['defensio'] = 'Defensio';
		$this->add_template( 'dashboard.block.defensio', dirname( __FILE__ ) . '/dashboard.block.defensio.php' );
		return $block_list;
	}

	/**
	 * Filter our dashboard module to add our output.
	 * @param array $module An array contain module data like title, content, etc
	 * @param int $module_id The modules id
	 * @param Theme $theme The theme object
	 * @return array Our module containing title, content, etc.
	 */
	public function action_block_content_defensio( Block $block, Theme $theme )
	{
		$stats = $this->theme_defensio_stats();
		// Show an error in the dashboard if Defensio returns a bad response.
		if ( !$stats ) {
			$block->error = _t('Bad Response From Server', 'defensio');
			return;
		}

		$block->accuracy = sprintf( '%.2f', $stats->accuracy * 100 );
		$block->spam = $stats->spam;
		$block->ham = $stats->ham;
		$block->false_negatives = $stats->false_negatives;
		$block->false_positives = $stats->false_positives;
	}

	/**
	 * Get the Defensio stats.
	 * @todo use cron to get stats, and "keep cache" system
	 * @return DefensioResponse The stats. Or NULL if no respnse.
	 */
	public function theme_defensio_stats()
	{
		if ( Cache::has( 'defensio_stats' ) ) {
			$stats = Cache::get( 'defensio_stats' );
		}
		else {
			try {
				$stats = $this->defensio->get_stats();
				Cache::set( 'defensio_stats', $stats );
			}
			catch ( Exception $e ) {
				EventLog::log( $e->getMessage(), 'notice', 'theme', 'Defensio' );
				return NULL;
			}
		}

		return $stats;
	}

	/**
	 * Scan a comment with defensio and set it's status.
	 * @param Comment $comment The comment object to scan
	 */
	private function audit_comment( Comment $comment )
	{
		$user = User::identify();
		$params = array(
			'user-ip' => long2ip( $comment->ip ),
			'article-date' => $comment->post->pubdate->format( 'Y/m/d' ),
			'comment-author' => $comment->name,
			'comment-type' => strtolower( $comment->typename ),
			'comment-content' => $comment->content_out,
			'comment-author-email' => $comment->email ? $comment->email : NULL,
			'comment-author-url' => $comment->url ? $comment->url : NULL,
			'permalink' => $comment->post->permalink,
			'referrer' => isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : NULL,
		);
		if ( $user instanceof User ) {
			$params['user-logged-in'] = $user->loggedin;
			// @todo test for administrator, editor, etc. as well
			$params['trusted-user'] = $user->loggedin;
			if ( $user->info->openid_url ) {
				$params['openid'] = $user->info->openid_url;
			}
		}

		if ( self::TEST_FORCE != FALSE ) {
			$params['test-force'] = self::TEST_FORCE;
		}

		$result = $this->defensio->audit_comment( $params );
		// see if it's spamm or the spaminess is greater than min allowed spaminess
		$min_spaminess = Options::get( self::OPTION_FLAG_SPAMINESS );
		if ( $result->spam == true && $result->spaminess >= ((int) $min_spaminess / 100) ) {
			$comment->status = 'spam';
			// this array nonsense is dumb
			$comment->info->spamcheck = array_unique(
				array_merge(
					(array) $comment->info->spamcheck,
					array( _t('Flagged as Spam by Defensio', 'defensio') )
				)
			);
		}
		else {
			// it's not spam so if auto_approve is set, approve it
			if ( Options::get( self::OPTION_AUTO_APPROVE ) == 'yes' ) {
				$comment->status = 'approved';
			}
			else {
				$comment->status = 'unapproved';
			}
		}
		$comment->info->defensio_signature = $result->signature;
		$comment->info->defensio_spaminess = $result->spaminess;
	}

	/**
	 * Hook in to scan the comment with Defensio. If it fails, add a cron to try again.
	 * @param Comment $comment The comment object that was inserted
	 */
	public function action_comment_insert_before( Comment $comment )
	{
		try {
			$this->audit_comment( $comment );
		}
		catch ( Exception $e ) {
			EventLog::log(
				_t('Defensio scanning for comment %s failed, adding to queue', array($comment->ip), 'defensio'),
				'notice', 'comment', 'Defensio'
			);
			$comment->status =  self::COMMENT_STATUS_QUEUED;
			$comment->info->spamcheck = array( _t('Queued for Defensio scan.', 'defensio') );
			// this could cause multiple crons without checking if there, but that's ok, it'll avoid races.
			$this->_add_cron();
			Session::notice( _t('Your comment is being scanned for spam.', 'defensio') );
		}
	}

	/**
	 * I should really comment all these functions. --matt
	 */
	protected function _add_cron( $time = 0 )
	{
		CronTab::add_single_cron(
				'defensio_queue',
				'defensio_queue',
				time() + $time,
				_t('Queued comments to scan with defensio, that failed first time', 'defensio')
			);
	}

	/**
	 * try to scan for MAX_RETRIES
	 */
	public function filter_defensio_queue($result = true)
	{
		$comments = Comments::get( array('status' => self::COMMENT_STATUS_QUEUED) );

		if ( count($comments) > 0 ) {
			$try_again = FALSE;
			foreach( $comments as $comment ) {
				// Have we tried yet
				if ( !$comment->info->defensio_retries ) {
					 $comment->info->defensio_retries = 1;
				}
				try {
					$this->audit_comment( $comment );
					$comment->update();
					EventLog::log(
						_t('Defensio scanning, retry %d, for comment %s succeded', array($comment->info->defensio_retries, $comment->ip), 'defensio'),
						'notice', 'comment', 'Defensio'
					);
				}
				catch ( Exception $e ) {
					if ( $comment->info->defensio_retries == self::MAX_RETRIES ) {
						EventLog::log(
							_t('Defensio scanning failed for comment %s. Could not connect to server. Marking unapproved.', array($comment->ip), 'defensio'),
							'notice', 'comment', 'Defensio'
						);
						$comment->status = 'unapproved';
						$comment->update();
					}
					else {
						EventLog::log(
							_t('Defensio scanning, retry %d, for comment %s failed', array($comment->info->defensio_retries, $comment->ip), 'defensio'),
							'notice', 'comment', 'Defensio'
						);
						// increment retries and set try_again
						$comment->info->defensio_retries = $comment->info->defensio_retries + 1;
						$comment->update();
						$try_again = TRUE;
					}
				}
			}
			// try again in RETRY_INTERVAL seconds if not scanned yet
			if ( $try_again ) {
				$this->_add_cron(self::RETRY_INTERVAL);
			}
		}
		return true;
	}

	public function action_admin_moderate_comments( $action, Comments $comments, AdminHandler $handler )
	{
		$false_positives = array();
		$false_negatives = array();

		foreach ( $comments as $comment ) {
			switch ( $action ) {
				case 'spam':
					if ( ( $comment->status == Comment::STATUS_APPROVED || $comment->status == Comment::STATUS_UNAPPROVED )
						&& isset($comment->info->defensio_signature) ) {
						$false_negatives[] = $comment->info->defensio_signature;
					}
					break;
				case 'approve':
					if ( $comment->status == Comment::STATUS_SPAM && isset($comment->info->defensio_signature) ) {
						$false_positives[] = $comment->info->defensio_signature;
					}
					break;
			}
		}

		try {
			if ( $false_positives ) {
				$this->defensio->report_false_positives( array( 'signatures' => $false_positives ) );
				Cache::expire('defensio_stats');
				$count = count($false_positives);
				Session::notice(sprintf(
					_n(
						'Reported %d false positive to Defensio',
						'Reported %d false positives to Defensio',
						$count,
						'defensio'
					),
					$count
				));
			}
			if ( $false_negatives ) {
				$this->defensio->report_false_negatives( array( 'signatures' => $false_negatives ) );
				Cache::expire('defensio_stats');
				$count = count($false_negatives);
				Session::notice(sprintf(
					_n(
						'Reported %d false negative to Defensio',
						'Reported %d false negatives to Defensio',
						$count,
						'defensio'
					),
					$count
				));
			}
		}
		catch ( Exception $e ) {
			EventLog::log( $e->getMessage(), 'notice', 'comment', 'Defensio' );
		}
	}

	public function action_post_insert_after( Post $post )
	{
		if ( Options::get( self::OPTION_ANNOUNCE_POSTS ) == 'yes' && $post->statusname == 'published' ) {
			$params = array(
				'article-author' => $post->author->username,
				'article-author-email' => $post->author->email,
				'article-title' => $post->title,
				'article-content' => $post->content,
				'permalink' => $post->permalink
			);

			try {
				$result = $this->defensio->announce_article( $params );
			}
			catch ( Exception $e ) {
				EventLog::log( $e->getMessage(), 'notice', 'content', 'Defensio' );
			}
		}
	}

	public function filter_list_comment_statuses( array $comment_status_list )
	{
		$comment_status_list[self::COMMENT_STATUS_QUEUED] = 'defensio queue';
		return $comment_status_list;
	}

	public static function get_spaminess_style( Comment $comment )
	{
		if ( isset($comment->info->defensio_spaminess) && $comment->status == Comment::status('spam') ) {
			$grad_hex = create_function( '$s,$e,$i', 'return (($e-$s)*$i)+$s;' );
			$start_hex = '#FFD6D7';
			$end_hex = '#F8595D';
			$border = ColorUtils::rgb_hex(
				array_combine(
					array('r','g','b'),
					array_map(
						$grad_hex,
						ColorUtils::hex_rgb($start_hex),
						ColorUtils::hex_rgb($end_hex),
						array_pad(array(), 3, $comment->info->defensio_spaminess)
					)
				)
			);
			return "border-left-color:#$border; border-right-color:#$border;";
		}
		elseif ( $comment->status == self::COMMENT_STATUS_QUEUED ) {
			return 'border-left: 3px solid #BCCFFF; border-right: 3px solid #BCCFFF;';
		}
		return '';
	}

	public function filter_comment_style( $style, Comment $comment )
	{
		if ( $style != '' ) {
			$style .= ' ';
		}
		$style .= self::get_spaminess_style($comment);
		return $style;
	}

	public function action_comment_info( Comment $comment )
	{
		if ( isset($comment->info->defensio_spaminess) ) {
			echo '<p class="keyval spam"><span class="label">' . _t('Defensio Spaminess:', 'defensio') . '</span>' . '<strong>' . ($comment->info->defensio_spaminess*100) . '%</strong></p>';
		}
	}
}

spl_autoload_register( array('Defensio', 'autoload') );
?>

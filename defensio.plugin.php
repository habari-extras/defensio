<?php

require_once "defensioapi.php";

class Defensio extends Plugin
{
	const MAX_RETRIES = 6;
	const RETRY_INTERVAL = 30;
	const COMMENT_STATUS_QUEUED = 9;
	
	private $defensio;

	public function set_priorities()
	{
		return array(
			'action_comment_insert_before' => 1
		);
	}

	public function action_plugin_activation()
	{
		Modules::add( 'Defensio' );
		Session::notice( _t('Please set your Defensio API Key in the configuration.', 'defensio') );
		Options::set( 'defensio__api_key', '' );
		Options::set( 'defensio__announce_posts', 'yes' );
		Options::set( 'defensio__auto_approve', 'no' );
	}

	public function action_plugin_deactivation()
	{
		Modules::remove_by_name( 'Defensio' );
	}

	public function filter_dash_modules( $modules )
	{
		$modules[] = 'Defensio';
		$this->add_template( 'dash_defensio', dirname( __FILE__ ) . '/dash_defensio.php' );
		return $modules;
	}

	public function filter_plugin_config( $actions, $plugin_id )
	{
		if ( $plugin_id == $this->plugin_id() ) {
			$actions[] = _t('Configure', 'defensio');
		}
		return $actions;
	}

	public function action_plugin_ui( $plugin_id, $action )
	{
		if ( $plugin_id == $this->plugin_id() ) {
			switch ( $action ) {
				case _t('Configure', 'defensio') :
					$ui = new FormUI( 'defensio' );

					// Add a text control for the address you want the email sent to
					$api_key = $ui->append( 'text', 'api_key', 'option:defensio__api_key', _t('Defensio API Key: ', 'defensio') );
					$api_key->add_validator( 'validate_required' );
					$api_key->add_validator( array( $this, 'validate_api_key' ) );
					
					// using yes/no is not ideal but it's what we got :(
					$announce_posts = $ui->append( 'select', 'announce_posts', 'option:defensio__announce_posts', _t('Announce New Posts To Defensio: ', 'defensio') );
					$announce_posts->options = array( 'yes' => _t('Yes', 'defensio'), 'no' => _t('No', 'defensio') );
					$announce_posts->add_validator( 'validate_required' );
					
					$auto_approve = $ui->append( 'select', 'auto_approve', 'option:defensio__auto_approve', _t('Automatically Approve Non-Spam Comments: ', 'defensio') );
					$auto_approve->options = array( 'no' => _t('No', 'defensio'), 'yes' => _t('Yes', 'defensio') );
					$auto_approve->add_validator( 'validate_required' );

					$register = $ui->append( 'static', 'register', '<a href="http://defensio.com/signup">' . _t('Get A New Defensio API Key.', 'defensio') . '</a>' );

					$ui->append( 'submit', 'save', _t( 'Save', 'defensio' ) );
					$ui->set_option( 'success_message', _t( 'Configuration saved', 'defensio' ) );
					$ui->out();
					break;
			}
		}
	}

	public function validate_api_key( $key )
	{
		try {
			DefensioAPI::validate_api_key( $key, Site::get_url( 'habari' ) );
		}
		catch ( Exception $e ) {
			return array( sprintf( _t('Sorry, the Defensio API key <b>%s</b> is invalid. Please check to make sure the key is entered correctly and is <b>registered for this site (%s)</b>.', 'defensio'), $key, Site::get_url( 'habari' ) ) );
		}
		return array();
	}

	public function action_init()
	{
		$this->defensio = new DefensioAPI( Options::get( 'defensio__api_key' ), Site::get_url( 'habari' ) );
		$this->load_text_domain( 'defensio' );
	}

	public function filter_dash_module_defensio( $module, $module_id, $theme )
	{
		$stats = $this->theme_defensio_stats();
		// Show an error in the dashboard if Defensio returns a bad response.
		if ( !$stats ) {
			$module['title'] = '<a href="' . URL::get( 'admin', 'page=comments' ) . '">' . _t('Defensio', 'defensio') . '</a>';
			$module['content'] = '<ul class=items"><li class="item clear">' . _t('Bad Response From Server', 'defensio') . '</li></ul>';
			return $module;
		}

		$theme->accuracy = sprintf( '%.2f', $stats->accuracy * 100 );
		$theme->spam = $stats->spam;
		$theme->ham = $stats->ham;
		$theme->false_negatives = $stats->false_negatives;
		$theme->false_positives = $stats->false_positives;

		$module['title'] = '<a href="' . htmlspecialchars( URL::get( 'admin', array( 'page' => 'comments', 'status' => Comment::STATUS_SPAM ) ), ENT_COMPAT, 'UTF-8' ) . '">'. _t('Defensio', 'defensio') . '</a>';
		$module['content'] = $theme->fetch( 'dash_defensio' );
		return $module;
	}
	
	/**
	 * @todo use cron to get stats, and "keep cache" system
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
				return null;
			}
		}

		return $stats;
	}
	
	private function audit_comment( Comment $comment )
	{
		$user = User::identify();
		$params = array(
			'user-ip' => long2ip( $comment->ip ),
			'article-date' => $comment->post->pubdate->format( 'Y/m/d' ),
			'comment-author' => $comment->name,
			'comment-type' => strtolower( $comment->typename ),
			'comment-content' => $comment->content_out,
			'comment-author-email' => $comment->email ? $comment->email : null,
			'comment-author-url' => $comment->url ? $comment->url : null,
			'permalink' => $comment->post->permalink,
			'referrer' => isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : null,
		);
		if ( $user instanceof User ) {
			$params['user-logged-in'] = $user->loggedin;
			// @todo test for administrator, editor, etc. as well
			$params['trusted-user'] = $user->loggedin;
			if ( $user->info->openid_url ) {
				$params['openid'] = $user->info->openid_url;
			}
		}

		$result = $this->defensio->audit_comment( $params );
		if ( $result->spam == true ) {
			$comment->status = 'spam';
			$comment->info->spamcheck = array_unique(array_merge((array) $comment->info->spamcheck, array( _t('Flagged as Spam by Defensio', 'defensio'))));
		}
		else {
			// it's not spam so if auto_approve is set, approve it
			if ( Options::get('defensio__auto_approve') == 'yes' ) {
				$comment->status = 'approved';
			}
			else {
				$comment->status = 'unapproved';
			}
		}
		$comment->info->defensio_signature = $result->signature;
		$comment->info->defensio_spaminess = $result->spaminess;
	}

	public function action_comment_insert_before( $comment )
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
		CronTab::add_single_cron( 'defensio_queue', 'defensio_queue', time(), _t('Queued comments to scan with defensio, that failed first time', 'defensio') );
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

	public function action_admin_moderate_comments( $action, $comments, $handler )
	{
		$false_positives = array();
		$false_negatives = array();

		foreach ( $comments as $comment ) {
			switch ( $action ) {
				case 'spam':
					if ( ( $comment->status == Comment::STATUS_APPROVED || $comment->status == Comment::STATUS_UNAPPROVED )
						&& isset($comment->info->defensio_signature) ) {
						$false_negatives[] = $comment->info->defensio_signature;
						Cache::expire('defensio_stats');
					}
					break;
				case 'approve':
					if ( $comment->status == Comment::STATUS_SPAM && isset($comment->info->defensio_signature) ) {
						$false_positives[] = $comment->info->defensio_signature;
						Cache::expire('defensio_stats');
					}
					break;
			}
		}

		try {
			if ( $false_positives ) {
				$this->defensio->report_false_positives( array( 'signatures' => $false_positives ) );
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

	public function action_post_insert_after( $post )
	{
		if ( Options::get( 'defensio__announce_posts' ) == 'yes' && $post->statusname == 'published' ) {
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
	
	public static function get_spaminess_style( $comment )
	{
		if ( isset($comment->info->defensio_spaminess) && $comment->status == Comment::status('spam')) {
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
	
	public function filter_comment_style( $style, $comment ) {
		if($style != '') {
			$style.= ' ';
		}
		$style.= self::get_spaminess_style($comment);
		return $style;
	}
	
	public function action_comment_info( $comment ) {
		if(isset($comment->info->defensio_spaminess)) {
			echo '<p class="keyval spam"><span class="label">' . _t('Defensio Spaminess:', 'defensio') . '</span>' . '<strong>' . ($comment->info->defensio_spaminess*100) . '%</strong></p>';
		}
	}
	
	/**
	 * Sort by spaminess when the status:spam filter is set
	 */
	public function filter_comments_actions( $actions, &$comments )
	{
		if ( preg_match( '/status:\s*spam/i', Controller::get_handler()->handler_vars['search'] )
			|| Comment::status(Controller::get_handler()->handler_vars['status']) == Comment::status('spam') ) {
			usort( $comments, 'Defensio::sort_by_spaminess' );
		}
		return $actions;
	}

	public static function sort_by_spaminess( $a, $b )
	{
		if ( isset($a->info->defensio_spaminess) && isset($b->info->defensio_spaminess) ) {
			if ( $a->info->defensio_spaminess == $b->info->defensio_spaminess ) {
				return 0;
			}
			return $a->info->defensio_spaminess > $b->info->defensio_spaminess ? -1 : 1;
		}
		elseif ( isset($a->info->defensio_spaminess) ) {
			return 0;
		}
		else {
			return 1;
		}
	}
}

?>

<?php

require_once "defensioapi.php";

class Defensio extends Plugin
{
	private $defensio;

	public function info()
	{
		return array(
			'name' => 'Defensio',
			'author' => 'Habari Community',
			'description' => 'Provides the Defensio spam filter webservice to Habari comments.',
			'url' => 'http://habariproject.org',
			'version' => '0.4',
			'license' => 'Apache License 2.0'
			);
	}

	public function set_priorities()
	{
		return array(
			'action_comment_insert_before' => 1
		);
	}

	public function action_plugin_activation( $file )
	{
		if ( realpath( $file ) == __FILE__ ) {
			Modules::add( 'Defensio' );
			Session::notice( _t('Please set your Defensio API Key in the configuration.', 'defensio') );
			Options::set( 'defensio__api_key', '' );
			Options::set( 'defensio__announce_posts', 'yes' );
		}
	}

	public function action_plugin_deactivation( $file )
	{
		if ( realpath( $file ) == __FILE__ ) {
			Modules::remove_by_name( 'Defensio' );
		}
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

					$announce_posts = $ui->append( 'select', 'announce_posts', 'option:defensio__announce_posts', _t('Announce New Posts To Defensio: ', 'defensio') );
					$announce_posts->options = array( 'yes' => _t('Yes', 'defensio'), 'no' => _t('No', 'defensio') );
					$announce_posts->add_validator( 'validate_required' );

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
		$this->add_template( 'defensio', dirname(__FILE__) . '/moderate.php' );
	}

	function action_admin_theme_get_spam( $handler, $theme )
	{
		$handler->fetch_comments( array( 'status' => 2, 'limit' => 60 ) );
		$theme->display( 'defensio' );
		exit;
	}

	function action_admin_theme_post_spam( $handler, $theme )
	{
		$this->action_admin_theme_get_spam( $handler, $theme );
	}

	function filter_adminhandler_post_loadplugins_main_menu( $menu )
	{
		$menu['spam'] = array( 'url' => URL::get( 'admin', 'page=spam' ), 'title' => _t('Manage all spam in the spam vault','defensio'), 'text' => _t('Spam Vault','defensio'), 'hotkey' => 'S', 'selected' => false );
		return $menu;
	}

	public function filter_dash_module_defensio( $module, $module_id, $theme )
	{
		$stats = $this->theme_defensio_stats();
		// Show an error in the dashboard if Defensio returns a bad response.
		if ( !$stats ) {
			$module['title'] = '<a href="' . Site::get_url('admin') . '/spam">' . _t('Defensio') . '</a>';
			$module['content'] = '<ul class=items"><li class="item clear">' . _t('Bad Response From Server') . '</li></ul>';
			return $module;
		}

		$theme->accuracy = sprintf( '%.2f', $stats->accuracy * 100 );
		$theme->spam = $stats->spam;
		$theme->ham = $stats->ham;
		$theme->false_negatives = $stats->false_negatives;
		$theme->false_positives = $stats->false_positives;

		$module['title'] = '<a href="' . Site::get_url('admin') . '/spam">' . _t('Defensio') . '</a>';
		$module['content'] = $theme->fetch( 'dash_defensio' );
		return $module;
	}

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

	public function action_comment_insert_before( $comment )
	{
		$user = User::identify();
		$params = array(
			'user-ip' => long2ip( $comment->ip ),
			'article-date' => date( 'Y/m/d', strtotime( $comment->post->pubdate ) ),
			'comment-author' => $comment->name,
			'comment-type' => strtolower( Comment::type_name( $comment->type ) ),
			'comment-content' => $comment->content_out,
			'comment-author-email' => $comment->email ? $comment->email : null,
			'comment-author-url' => $comment->url ? $comment->url : null,
			'permalink' => $comment->post->permalink,
			'referrer' => isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : null,
		);
		if ( $user instanceof User ) {
			$params['user-logged-in'] = $user instanceof User;
			// test for administrator, editor, etc. as well
			$params['trusted-user'] = $user instanceof User;
			if ( $user->info->openid_url ) {
				$params['openid'] = $user->info->openid_url;
			}
		}

		try {
			$result = $this->defensio->audit_comment( $params );
			if ( $result->spam == true ) {
				$comment->status = 'spam';
				$comment->info->spamcheck = array_unique(array_merge((array) $comment->info->spamcheck, array( _t('Flagged as Spam by Defensio', 'defensio'))));
			}
			$comment->info->defensio_signature = $result->signature;
			$comment->info->defensio_spaminess = $result->spaminess;
		}
		catch ( Exception $e ) {
			EventLog::log( $e->getMessage(), 'notice', 'comment', 'Defensio' );
		}
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
				Session::notice( sprintf( _t('Reported %d false positives to Defensio', 'defensio'), count($false_positives) ) );
			}
			if ( $false_negatives ) {
				$this->defensio->report_false_negatives( array( 'signatures' => $false_negatives ) );
				Session::notice( sprintf( _t('Reported %d false negatives to Defensio', 'defensio'), count($false_negatives) ) );
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

	public static function get_spaminess_style( $comment )
	{
		if ( isset($comment->info->defensio_spaminess) ) {
			switch ( $comment->info->defensio_spaminess ) {
				case $comment->info->defensio_spaminess > 0.6:
					return 'background:#faa;';
				case $comment->info->defensio_spaminess > 0.3:
					return 'background:#fca;';
				case $comment->info->defensio_spaminess > 0.1:
					return 'background:#fea;';
			}
		}
		return 'background:#e2dbe3;';
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

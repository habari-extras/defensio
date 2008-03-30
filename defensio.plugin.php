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
			'version' => '0.1',
			'license' => 'Apache License 2.0'
			);
	}
	
	public function set_priorities()
	{
		return array(
			'action_comment_insert_before' => 1
			);
	}
	
	private static function default_options()
	{
		return array(
			'api_key' => '',
			'announce_posts' => 'yes',
			);
	}
	
	public function action_plugin_activation( $file )
	{
		if ( $file == $this->get_file() ) {
			Session::notice( _t('Please set your Defensio API Key in the configuration.') );
			foreach ( self::default_options() as $name => $value ) {
				Options::set( 'defensio:' . $name, $value );
			}
		}
	}
	
	public function filter_plugin_config( $actions, $plugin_id )
      {
		if ( $plugin_id == $this->plugin_id() ) {
			$actions[]= _t('Configure');
		}
		return $actions;
	}

	public function action_plugin_ui( $plugin_id, $action )
	{
		if ( $plugin_id == $this->plugin_id() ) {
			switch ( $action ) {
				case _t('Configure') :
					$ui = new FormUI( 'defensio' );
					
					// Add a text control for the address you want the email sent to
					$api_key= $ui->add( 'text', 'api_key', 'Defensio API Key: ' );
					$api_key->add_validator( 'validate_required' );
					$api_key->add_validator( array( $this, 'validate_api_key' ) );
					
					$announce_posts= $ui->add( 'select', 'announce_posts', 'Announce New Posts To Defensio: ' );
					$announce_posts->options= array( 'yes' => _t('Yes'), 'no' => _t('No') );
					$announce_posts->add_validator( 'validate_required' );
					
					$register= $ui->add( 'static', 'register', '<a href="http://defensio.com/signup">' . _t('Get A New Defensio API Key.') . '</a>' );
					
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
			return array( sprintf( _t('Sorry, the Defensio API key <b>%s</b> is invalid. Please check to make sure the key is entered correctly and is <b>registered for this site (%s)</b>.'), $key, Site::get_url( 'habari' ) ) );
		}
		return array();
	}
	
	public function action_init()
	{
		if ( Options::get( 'defensio:api_key' ) == '' ) {
			EventLog::log( 'You must enter a valid API key for Defensio to work', 'notice', 'default', 'Defensio' );
		}
		else {
			$this->defensio= new DefensioAPI( Options::get( 'defensio:api_key' ), Site::get_url( 'habari' ) );
		}
	}
	
	public function filter_include_template_file( $file, $name )
	{
		if ( $name == 'moderate' ) {
			return dirname( __FILE__ ) . '/moderate.php';
		}
		return $file;
	}
	
	public function theme_defensio_stats()
	{
		if ( Cache::has( 'defensio_stats' ) ) {
			$stats= Cache::get( 'defensio_stats' );
		}
		else {
			try {
				$stats= $this->defensio->get_stats();
				Cache::set( 'defensio_stats', $stats );
			}
			catch ( Exception $e ) {
				EventLog::log( $e->getMessage(), 'notice', 'theme', 'Defensio' );
				return null;
			}
		}
		// this should be a template.
		return <<<STATS
			<table width="100%">
				<tr><td>Accuracy </td><td> {$stats->accuracy}</td></tr>
				<tr class="alt"><td>Total Spam </td><td> {$stats->spam}</td></tr>
				<tr><td>Total Ham </td><td> {$stats->ham}</td></tr>
			</table>
STATS;
	}
	
	public function action_comment_insert_before( $comment )
	{
		$user= User::identify();
		$params= array(
			'user-ip' => $comment->ip,
			'article-date' => date( 'Y/m/d', strtotime( $comment->post->pubdate ) ),
			'comment-author' => $comment->name,
			'comment-type' => strtolower( Comment::type_name( $comment->type ) ),
			'comment-content' => $comment->content_out,
			'comment-author-email' => $comment->email ? $comment->email : null,
			'comment-author-url' => $comment->url ? $comment->url : null,
			'permalink' => $comment->post->permalink,
			'referrer' => $_SERVER['HTTP_REFERER'] ? $_SERVER['HTTP_REFERER'] : null,
			'user-logged-in' => $user instanceof User,
			'trusted-user' => $user instanceof User, // test for administrator, editor, etc. as well
			//'openid' => '',
			//'test-force' => 'spam,0.2',
		);
		
		try {
			$result= $this->defensio->audit_comment( $params );
			if ( $result->spam == true ) {
				$comment->status= 'spam';
				$comment->info->spamcheck= array_unique(array_merge((array) $comment->info->spamcheck, array('Flagged as Spam by Defensio')));
			}
			$comment->info->defensio_signature= $result->signature;
			$comment->info->defensio_spaminess= $result->spaminess;
		}
		catch ( Exception $e ) {
			EventLog::log( $e->getMessage(), 'notice', 'comment', 'Defensio' );
		}
	}
	
	// this is an actio I added to filter moderated comments for spam filter training.
	// It needs to be added to habari for Defensio training to work.
	public function action_admin_moderate_comments( $comment_ids, $comments, $handler )
	{
		$false_positives= array();
		$false_negatives= array();
		
		foreach ( $comments as $comment ) {
			switch ( $comment_ids[$comment->id] ) {
				case 'spam':
					if ( ( $comment->status == Comment::STATUS_APPROVED || $comment->status == Comment::STATUS_UNAPPROVED )
						&& isset($comment->info->defensio_signature) ) {
						$false_negatives[]= $comment->info->defensio_signature;
					}
					break;
				case 'approve':
					if ( $comment->status == Comment::STATUS_SPAM && isset($comment->info->defensio_signature) ) {
						$false_positives[]= $comment->info->defensio_signature;
					}
					break;
			}
		}
		
		try {
			if ( $false_positives ) {
				$this->defensio->report_false_positives( array( 'signatures' => $false_positives ) );
				Session::notice( sprintf( _t('Reported %d false positives to Defensio'), count($false_positives) ) );
			}
			if ( $false_negatives ) {
				$this->defensio->report_false_negatives( array( 'signatures' => $false_negatives ) );
				Session::notice( sprintf( _t('Reported %d false negatives to Defensio'), count($false_negatives) ) );
			}
		}
		catch ( Exception $e ) {
			EventLog::log( $e->getMessage(), 'notice', 'comment', 'Defensio' );
		}
	}
	
	public function action_post_insert_after( $post )
	{
		if ( Options::get( 'defensio:announce_posts' ) == 'yes' && $post->statusname == 'published' ) {
			$params= array(
				'article-author' => $post->author->username,
				'article-author-email' => $post->author->email,
				'article-title' => $post->title,
				'article-content' => $post->content,
				'permalink' => $post->permalink
			);
			
			try {
				$result= $this->defensio->announce_article( $params );
			}
			catch ( Exception $e ) {
				EventLog::log( $e->getMessage(), 'notice', 'content', 'Defensio' );
			}
		}
	}
	
	public function action_post_update_after( $post )
	{
		$this->action_post_insert_after( $post );
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
	
	public function sort_by_spaminess( $a, $b )
	{
		if ( isset($a->info->defensio_spaminess) && isset($b->info->defensio_spaminess) ) {
			if ( $a->info->defensio_spaminess == $b->info->defensio_spaminess ) {
				return 0;
			}
			return $a->info->defensio_spaminess > $b->info->defensio_spaminess ? -1 : 1;
		}
		return 0;
	}
}

?>
<?php

class DefensioAPI
{
	const API_VERSION= '1.2';
	const FORMAT= 'xml';
	const SERVICE_TYPE= 'blog';
	
	private $api_key;
	private $owner_url;
	private $valid_actions= array( 'validate-key', 'audit-comment', 'announce-article', 'report-false-positives', 'report-false-negatives', 'get-stats' );
	
	public function __construct( $api_key, $owner_url )
	{
		$this->api_key= $api_key;
		$this->owner_url= $owner_url;
	}
	
	public function __call( $action, $args )
	{
		if ( !$this->api_key ) {
			throw new Exception( 'API key is required' );
		}
		$action= str_replace( '_', '-', $action );
		if ( !in_array( $action, $this->valid_actions ) ) {
			throw new Exception( 'Defensio cannot proccess ' . $action );
		}
		$params= new DefensioParams( $args?$args[0]:array() );
		$params->owner_url= $this->owner_url;
		
		$response= $this->call( $action, $params );
		if ( $response->status == 'fail' ) {
			throw new Exception( $action . ': ' . $response->message );
		}
		return $response;
	}
	
	private function call( $action, DefensioParams $params )
	{
		$client= new RemoteRequest( $this->build_url( $action ), 'POST' );
		$client->set_body( $params->get_post_body() );
		if ( $client->execute() ) {
			$response= new DefensioResponse( $client->get_response_body() );
			unset( $client );
			return $response;
		}
		else {
			throw new Exception( 'communication with Defensio is down.' );
		}
	}
	
	private function build_url( $action )
	{
		return sprintf( 'http://api.defensio.com/%1$s/%2$s/%3$s/%4$s.%5$s',
			self::SERVICE_TYPE,
			self::API_VERSION,
			$action,
			$this->api_key,
			self::FORMAT
		);
	}
	
	public static function validate_api_key( $key, $owner_url )
	{
		$defensio= new DefensioAPI( $key, $owner_url );
		return $defensio->validate_key();
	}
}

class DefensioParams
{
	private $post_data= array();
	
	public function __construct( $params= array() )
	{
		foreach( $params as $name => $value ) {
			if ( $value ) {
				$this->{$name}= $value;
			}
		}
	}
	
	public function __set( $name, $value )
	{
		$name= str_replace( '_', '-', $name );
		switch ( $name ) {
			case 'signatures':
				if ( is_array( $value ) ) {
					$this->post_data[$name]= trim( implode( ',', (array) $value ) );
				}
				else {
					$this->post_data[$name]= $value;
				}
				break;
			default:
				$this->post_data[$name]= $value;
				break;
		}
	}
	
	public function __get( $name )
	{
		$name= str_replace( '_', '-', $name );
		if ( array_key_exists( $name, $this->post_data ) ) {
			return $this->post_data[$name];
		}
		return null;
	}
	
	public function get_post_body()
	{
		return http_build_query( $this->post_data, '', '&' );
	}
}

class DefensioResponse
{
	private $nodes= array();
	
	public function __construct( $xml )
	{
		$xml= new SimpleXMLElement( $xml );
		foreach ( $xml as $element ) {
			$node= new DefensioNode( $element );
			$this->nodes[$node->name]= $node;
		}
	}
	
	public function __set( $name, $value )
	{
		$name= str_replace( '_', '-', $name );
		$this->nodes[$name]= $value;
	}
	
	public function __get( $name )
	{
		$name= str_replace( '_', '-', $name );
		if ( array_key_exists( $name, $this->nodes ) ) {
			return $this->nodes[$name]->value;
		}
		return null;
	}
}

class DefensioNode
{
	public $value;
	public $name;
	public $type;
	
	public function __construct( SimpleXMLElement $element )
	{
		$this->name= (string) $element->getName();
		$atts= $element->attributes();
		if ( isset( $atts['type'] ) ) {
			$this->type= (string) $atts['type'];
			$this->value= $this->settype( $element, $this->type );
		}
		else {
			$this->type= 'string';
			$this->value= (string) $element;
		}
	}
	
	public function __toString()
	{
		return $this->value;
	}
	
	private function settype( $element, $type )
	{
		if ( $type == 'boolean' || $type == 'bool' ) {
			return (string) $element == 'true' ? true : false;
		}
		else {
			settype( $element, $type );
			return $element;
		}
	}
}

?>
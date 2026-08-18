<?php

if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/** Deterministic, versioned mission plans for the SQL agency runtime. */
final class PRSTUDIO_UC_Playbook_Engine {
	public const VERSION = '17.0.0';
	private const TYPES = array( 'site_guardian','social_growth','commerce_growth','browser_deep_audit' );

	public static function types(): array { return self::TYPES; }
	public static function supports( string $type ): bool { return in_array( sanitize_key( $type ), self::TYPES, true ); }

	private static function step( string $id, string $handler, array $arguments = array(), array $policy = array() ): array {
		return array(
			'id'=>$id,
			'handler'=>$handler,
			'arguments'=>$arguments,
			'read_only'=>! array_key_exists('read_only',$policy) || (bool)$policy['read_only'],
			'requires_browser'=>!empty($policy['requires_browser']),
			'timeout_seconds'=>max(5,min(300,(int)($policy['timeout_seconds']??60))),
		);
	}

	private static function url( array $context ): string {
		$url = function_exists('esc_url_raw') ? esc_url_raw((string)($context['url']??'')) : (string)($context['url']??'');
		if(''===$url && function_exists('home_url'))$url=(string)home_url('/');
		return $url;
	}

	public static function build( string $type, array $context = array() ): array {
		$type=sanitize_key($type);if(!self::supports($type))return array();$url=self::url($context);
		switch($type){
			case 'site_guardian':
				$steps=array(
					self::step('sentinel','sentinel.scan',array('scope'=>$context['scope']??array('health','queue','content'),'limit'=>(int)($context['limit']??100))),
					self::step('twin','twin.sync',array('scope'=>array('site','content','commerce'),'limit'=>(int)($context['limit']??250))),
					self::step('opportunities','opportunity.rank',array('domains'=>array('site','commerce'),'limit'=>(int)($context['opportunity_limit']??20))),
				);break;
			case 'social_growth':
				$steps=array(
					self::step('social_insights','social.insights',array('platform'=>(string)($context['platform']??''),'account'=>(string)($context['account']??($context['account_id']??'')),'limit'=>(int)($context['limit']??50))),
					self::step('social_opportunities','opportunity.rank',array('domains'=>array('social'),'limit'=>(int)($context['opportunity_limit']??20))),
				);
				if(!empty($context['browser_observe'])&&''!==$url){$steps[]=self::step('social_browser_observe','browser.action',array('action'=>'playwright_observation_bundle','arguments'=>array('url'=>$url)),array('requires_browser'=>true));}
				break;
			case 'commerce_growth':
				$steps=array(
					self::step('commerce_twin','twin.sync',array('scope'=>array('site','commerce'),'limit'=>(int)($context['limit']??500))),
					self::step('commerce_opportunities','opportunity.rank',array('domains'=>array('commerce'),'limit'=>(int)($context['opportunity_limit']??20))),
				);break;
			case 'browser_deep_audit':
				$steps=array(
					self::step('open','browser.action',array('action'=>'playwright_new_page','arguments'=>array('url'=>$url)),array('requires_browser'=>true)),
					self::step('observe','browser.action',array('action'=>'playwright_observation_bundle','arguments'=>array()),array('requires_browser'=>true)),
					self::step('accessibility','browser.action',array('action'=>'playwright_accessibility_scan','arguments'=>array()),array('requires_browser'=>true)),
					self::step('screenshot','browser.action',array('action'=>'playwright_screenshot_page','arguments'=>array()),array('requires_browser'=>true)),
				);break;
			default:$steps=array();
		}
		$plan=array('version'=>self::VERSION,'type'=>$type,'steps'=>$steps,'created_from'=>'deterministic_catalog');
		$plan['hash']=hash('sha256',PRSTUDIO_UC_Idempotency::canonical_json($plan));
		return $plan;
	}

	public static function describe(): array {
		$out=array();foreach(self::TYPES as $type){$plan=self::build($type,array());$out[$type]=array('version'=>self::VERSION,'steps'=>count($plan['steps']??array()),'plan_hash'=>$plan['hash']??'');}return $out;
	}
}

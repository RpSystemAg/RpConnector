<?php

if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
require_once __DIR__ . '/class-prstudio-uc-site-learning.php';

/** Deterministic, versioned mission plans for the SQL agency runtime. */
final class PRSTUDIO_UC_Playbook_Engine {
	public const VERSION = '1.1.0';
	private const TYPES = array( 'site_guardian','social_growth','commerce_growth','browser_deep_audit','site_study' );

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
		$type=sanitize_key($type);if(!self::supports($type))return array();$url=self::url($context);$site_learning=array();
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
			case 'site_study':
				$study_context=$context;
				if(''!==$url)$study_context['url']=$url;
				$site_learning=PRSTUDIO_UC_Site_Learning::prepare_context($study_context);
				$steps=array(
					self::step('site_study_discovery','browser.action',array('action'=>'playwright_link_crawl','arguments'=>(array)($site_learning['crawler_arguments']??array())),array('requires_browser'=>true,'timeout_seconds'=>300)),
				);break;
			default:$steps=array();
		}
		$plan=array('version'=>self::VERSION,'type'=>$type,'steps'=>$steps,'created_from'=>'deterministic_catalog');
		if('site_study'===$type){$plan['site_learning']=array_intersect_key($site_learning,array_flip(array('module_id','origin','run_id')));}
		$plan['hash']=hash('sha256',PRSTUDIO_UC_Idempotency::canonical_json($plan));
		return $plan;
	}

	public static function describe(): array {
		$out=array();
		foreach(self::TYPES as $type){
			if('site_study'===$type){
				$descriptor=array('version'=>self::VERSION,'type'=>'site_study','steps'=>array(array('id'=>'site_study_discovery','handler'=>'browser.action','read_only'=>true,'requires_browser'=>true,'action'=>'playwright_link_crawl')),'created_from'=>'deterministic_catalog');
				$out[$type]=array('version'=>self::VERSION,'steps'=>1,'plan_hash'=>hash('sha256',PRSTUDIO_UC_Idempotency::canonical_json($descriptor)));
				continue;
			}
			$plan=self::build($type,array());$out[$type]=array('version'=>self::VERSION,'steps'=>count($plan['steps']??array()),'plan_hash'=>$plan['hash']??'');
		}
		return$out;
	}
}

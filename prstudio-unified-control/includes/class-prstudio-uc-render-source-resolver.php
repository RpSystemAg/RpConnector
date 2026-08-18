<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
/** Resolve the WordPress object/template/theme assets that actually render a public URL. */
final class PRSTUDIO_UC_Render_Source_Resolver {
    public const VERSION='1.0.0';
    private static function same_site(string $url):bool{$home=wp_parse_url(home_url('/'),PHP_URL_HOST);$host=wp_parse_url($url,PHP_URL_HOST);return $home&&$host&&strtolower((string)$home)===strtolower((string)$host);}
    private static function template_posts(string $slug):array{
        $rows=get_posts(array('post_type'=>array('wp_template','wp_template_part'),'post_status'=>array('publish','auto-draft'),'name'=>$slug,'posts_per_page'=>20,'orderby'=>'modified','order'=>'DESC','suppress_filters'=>false));$out=array();foreach($rows as $p){if(!$p instanceof WP_Post)continue;$out[]=array('id'=>(int)$p->ID,'post_type'=>$p->post_type,'slug'=>$p->post_name,'title'=>$p->post_title,'modified_gmt'=>get_post_modified_time('c',true,$p),'content_sha256'=>hash('sha256',(string)$p->post_content));}return $out;
    }
    public static function resolve(array $args){
        $url=esc_url_raw((string)($args['url']??''));if(!$url||!self::same_site($url))return new WP_Error('render_source_url_invalid','Render source resolver accepts only same-site HTTP(S) URLs.',array('status'=>400));
        $path=(string)(wp_parse_url($url,PHP_URL_PATH)??'/');$is_home='/'===rtrim($path,'/')||''===rtrim($path,'/');$page_on_front=(int)get_option('page_on_front',0);$post_id=$is_home&&$page_on_front?$page_on_front:(int)url_to_postid($url);$post=$post_id?get_post($post_id):null;
        $page_template=$post instanceof WP_Post?get_page_template_slug($post_id):'';$theme=get_stylesheet();$template_slug=$is_home?'front-page':(($post instanceof WP_Post&&'page'===$post->post_type)?('page-'.$post->post_name):'index');$block_templates=self::template_posts($template_slug);
        $theme_files=array();$base=get_stylesheet_directory();foreach(array('templates/'.$template_slug.'.html','templates/front-page.html','front-page.php','page.php','index.php','assets/css/dist/rp-home-lean.css','style.css') as $rel){$p=trailingslashit($base).$rel;if(is_readable($p))$theme_files[]=array('path'=>$rel,'sha256'=>hash_file('sha256',$p),'bytes'=>filesize($p));}
        $authority='theme_template';$authority_ref=$template_slug;if($block_templates){$authority='wp_template';$authority_ref=(string)$block_templates[0]['id'];}elseif($post instanceof WP_Post){$authority='post_content';$authority_ref=(string)$post_id;}
        return array('ok'=>true,'version'=>self::VERSION,'url'=>$url,'is_home'=>$is_home,'page_on_front'=>$page_on_front,'resolved_post'=>$post instanceof WP_Post?array('id'=>$post_id,'post_type'=>$post->post_type,'status'=>$post->post_status,'slug'=>$post->post_name,'modified_gmt'=>get_post_modified_time('c',true,$post),'content_sha256'=>hash('sha256',(string)$post->post_content),'page_template'=>$page_template):null,'block_templates'=>$block_templates,'theme'=>$theme,'theme_files'=>$theme_files,'authoritative_source'=>array('kind'=>$authority,'reference'=>$authority_ref),'recommended_mutation_path'=>$authority==='wp_template'?'content.transaction.patch_on_template_post':($authority==='post_content'?'content.transaction.patch':'toolchain.filesystem.write'),'browser_verify_url'=>$url,'evidence'=>'wordpress_runtime_resolution');
    }
}

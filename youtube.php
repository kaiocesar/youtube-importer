<?php 
/**
 *	Script para obter vídeos de canal do YouTube
 *	e transforma-los para posts
 *	@author Kaio Cesar
 *	@version 0.0.1
 */

error_reporting(0);

// refactor (painel wordpress)
$Youtube_API_Key = "*******";
$Youtube_channel_id = "*******";


// Fake CRON: Executar script apenas UMA vez por dia
$last_videos = get_option('last_get_videos');
if ($last_videos===false) {
	add_option('last_get_videos', date('Y-m-d'));
	$youtube = new CronYouTubeSync($Youtube_API_Key, $Youtube_channel_id);
	$youtube->runSync();
} else {
	if($last_videos!=date('Y-m-d') || (array_key_exists("_hack", $_GET))) {
		$youtube = new CronYouTubeSync($Youtube_API_Key, $Youtube_channel_id);
		$youtube->runSync();
		update_option('last_get_videos', date('Y-m-d'));
	}
}


class CronYouTubeSync 
{

	protected $youtube_api_key;
	protected $youtube_channel_id;
	protected $youtube_total_videos;
	protected $youtube_order;
	protected $youtube_post_type;

	public function __construct($yt_key=null, $yt_channel_id=null,$yt_total=50, $order='date', $post_type='videos') {
		$this->youtube_api_key = $yt_key;
		$this->youtube_channel_id = $yt_channel_id;
		$this->youtube_total_videos = $yt_total;
		$this->youtube_order = $order;
		$this->youtube_post_type = $post_type;
	}


	protected function add_metapost_fields($fields) {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$sql = "INSERT INTO {$prefix}postmeta (post_id, meta_key, meta_value) VALUES({$fields['post_id']}, '{$fields['meta_key']}', '{$fields['meta_value']}'); ";
		return $wpdb->query($sql);
	}

	protected function force_post_update($post_id) {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$sql = "UPDATE {$prefix}posts SET post_status = 'publish' WHERE ID = {$post_id} "; 
		return $wpdb->query($sql);
	}

	protected function get_metapost($value) {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$query = "SELECT count(*) as TOTAL FROM {$prefix}postmeta WHERE meta_key = 'strongtv_video_id' AND meta_value = '{$value}'";
		$results = $wpdb->get_results( $query );
		return $results;
	}

	public function runSync()
	{
		$users = get_users(array('rule'=>'admin'));
		$user_id = 1;
		if (count($users)) {
			$user_id = $users[0]->data->ID;
		}

		$order= "date"; //allowed order : date,rating,relevance,title,videocount,viewcount
		$url = "https://www.googleapis.com/youtube/v3/search?key=".$this->youtube_api_key
			."&channelId=".$this->youtube_channel_id."&part=snippet&order=".$this->youtube_order."&maxResults=".$this->youtube_total_videos."&format=json";

		$fields = array();

		$fields_string ='';
		foreach($fields as $key=>$value) { 
		    $fields_string .= $key.'='.$value.'&'; 
		}

		rtrim($fields_string, '&');

		$ch = curl_init();

		curl_setopt($ch,CURLOPT_URL, $url);

		if ($fields_string!='query=&') {
		    curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
		}

		curl_setopt($ch,CURLOPT_CUSTOMREQUEST, 'GET');
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		$results = curl_exec($ch);
		$videos = json_decode($results);
		curl_close($ch);

		if (count($videos)) {
			$post_type = $this->youtube_post_type;

			if (array_key_exists("error", $videos)) {
				continue;
			}
			if ($videos->pageInfo->totalResults==0) {
				continue;
			}
			if (!array_key_exists("items", $videos)) {
				continue;
			}

			$items = $videos->items;


			foreach ($items as $key => $item) {

				if (!array_key_exists("videoId", $item->id)) {
					continue;
				}

				$_videoId = $item->id->videoId;
				$_date = $item->snippet->publishedAt;
				$_title = $item->snippet->title;
				$_description = $item->snippet->description;
				$_datepub = '';

				$check = $this->get_metapost($_videoId);

				if($check[0]->TOTAL==0) {
					$dtoday = new DateTime();
					$dtoday = date('Y-m-d H:i:s');

					if ($_date) {
						$dnew = new DateTime($_date);
						$_datepub = $dnew->format('Y-m-d H:i:s');
					} else {
						$_datepub = $dtoday;
					}
					
					// create a post
					$my_post = array(
						  'post_title'    => wp_strip_all_tags( $_title  ),
						  'post_content'  => $_description, 
						  'post_status'   => 'publish',
						  'post_author'   => $user_id,
						  'post_date'     => $dtoday,
						  'post_parent'	  => 0,
						  'post_type'	  => $post_type
					);

					$idPost = wp_insert_post($my_post);

					// id do video
					$q1 = $this->add_metapost_fields(array(
						'post_id'=>$idPost, 
						'meta_key'=>'strongtv_video_id', 
						'meta_value'=> $_videoId
					));	
					$q2 = $this->add_metapost_fields(array(
						'post_id'=>$idPost, 
						'meta_key'=>'_strongtv_video_id', 
						'meta_value'=>'field_'.time()
					));	
					
					// data de publicação
					$q3 = $this->add_metapost_fields(array(
						'post_id'=>$idPost, 
						'meta_key'=>'strongtv_publi', 
						'meta_value'=> $_datepub
					));	
					$q4 = $this->add_metapost_fields(array(
						'post_id'=>$idPost, 
						'meta_key'=>'_strongtv_publi', 
						'meta_value'=>'field_'.time()
					));

					// Update post
					$this->force_post_update( $idPost );
					
				} // total
			}
		}

	} // runSync

}
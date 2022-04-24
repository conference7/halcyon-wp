<?php
/**
 * Plugin Name: Premia Admin
 */

namespace Premia_Admin;

require 'vendor/autoload.php';

class Main {

	private $api_url = 'http://164.92.151.1:4243';
	private $domain = "premia.tmprly.com";

	public function __construct() {
		$this->hooks();
		
	}

	public function hooks() {
		add_action('init', array($this, 'register_post_types'));
		add_action('acf/save_post', array($this, 'save_post'), 20);
		add_action('trashed_post', array($this, 'remove_env'), 20);
		add_filter('acf/load_field/key=field_625fa86ec3f06', array($this, 'add_details'));
		add_filter('acf/load_field/key=field_625faaf581eec', array($this, 'add_manage_buttons'));
		add_filter('acf/load_field/name=container_ids', array($this, 'set_read_only'));
		add_filter('acf/load_field/name=port', array($this, 'set_read_only'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

		$this->rest_init();
	}

	public function rest_init() {
		add_action('rest_api_init', array($this, 'register_endpoints'));
	}

	public function enqueue_scripts() {
		wp_enqueue_script('premia-admin-scripts', plugin_dir_url(__FILE__) . 'dist/index.js', array('wp-api'), filemtime(plugin_dir_path(__FILE__) . 'dist/index.js'));
	    wp_localize_script( 'premia-admin-scripts', 'premia_admin_data',
			array( 
				'api_url' => $this->api_url,
			)
		);
	}

	public function get_container_ids($post_id) {
		$container_ids = get_field('container_ids', $post_id);
		return array_filter(explode(',', $container_ids));
	}

	public function register_post_types() {
		register_post_type(
			'environment',
			array(
				'label' => 'Environments',
				'public' => true
			)
		);
	}

	public function save_post($post_id) {

		$post = get_post($post_id);

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_autosave( $post ) || $post->post_status === 'auto-draft' || wp_is_post_revision( $post_id ) ) {
			 return;
		}

		$ids = $this->create_environment($post);

		foreach ($ids as $id) {
			$this->docker_request('POST', '/containers/'.$id.'/start');
		}

	}

	public function create_environment($post) {

		$container_ids = get_field('container_ids', $post->ID);
		$container_ids = array_filter(explode(',', $container_ids));

		// Get a port.
		$port = $this->get_available_port();
		update_field('port', $port);

		$requests = [];
		
		$requests[] = $this->create_ssh($post);
		$requests[] = $this->create_database($post);
		$requests[] = $this->create_wordpress($post);

		foreach ($requests as $response) {
			$code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);
			$data = json_decode($body);
			
			switch($code) {
				case 201:
					$container_ids[] = $data->Id;
				break;
				default:
				break;
			}
		}

		update_field('container_ids', implode(',', $container_ids), $post->ID );

		return $container_ids;
	}

	public function get_available_port() {
		return $this->get_random_port();
	}

	public function get_random_port() {
		$start = 18000;
		$end = 18999;

		$used_ports = get_option('premia_used_ports');
		if (!is_array($used_ports)) {
			$used_ports = array();
		}

		if (count($used_ports) < 999) {
			$port = rand($start, $end);
			if (in_array($port, $used_ports)) {
				$port = $this->get_random_port();
			}
		} else {
			$port = 'oops';
		}
		return $port;
	}

	public function get_hostname($post) {

		$hostname = $post->post_name;

		$local = get_field('local', $post->ID);

		if (true === $local) {
			$hostname = "{$hostname}.local";
		}

		return "{$hostname}.{$this->domain}";
	}

	public function create_wordpress($post) {

		$hostname = $this->get_hostname($post);

		return $this->docker_request('POST', '/containers/create?name=' . $post->post_name .'-wp', array(
			"Image" => "wordpress",
			"Env" => [
				"WORDPRESS_DB_HOST={$post->post_name}-db",
				"WORDPRESS_DB_USER=exampleuser",
				"WORDPRESS_DB_PASSWORD=examplepass",
				"WORDPRESS_DB_NAME=exampledb"
			],
			"Labels" => [
				"traefik.enable" => "true",
				"traefik.http.routers.{$post->post_name}.rule" => "Host(`{$hostname}`)",
				"traefik.http.routers.{$post->post_name}.entrypoints" => "websecure",
				"traefik.http.routers.{$post->post_name}.tls.certresolver" => "myresolver"
			],
			"ExposedPorts" => [
				"8080/tcp" => (object) []
			],
			"HostConfig" => [
				"NetworkMode" => "swarm",
				"Binds" => [
					"/home/mklasen/halcyon-wp/users/home/{$post->post_name}/config/web/themes:/var/www/html/wp-content/themes",
					"/home/mklasen/halcyon-wp/users/home/{$post->post_name}/config/web/plugins:/var/www/html/wp-content/plugins",
					"/home/mklasen/halcyon-wp/users/home/{$post->post_name}/config/wp:/var/www/html"
				 ]
			]
		));
	}

	public function create_database($post) {
		return $this->docker_request('POST', '/containers/create?name=' . $post->post_name . '-db', array(
			"Image" => "mysql:5.7",
			"Env" => [
				"MYSQL_ROOT_PASSWORD=somewordpress",
				"MYSQL_DATABASE=exampledb",
				"MYSQL_USER=exampleuser",
				"MYSQL_PASSWORD=examplepass"
			],
			"HostConfig" => [
				"NetworkMode" => "swarm"
			]
		));
	}

	public function create_ssh($post) {
		$port = get_field('port', $post->ID);
		return $this->docker_request('POST', '/containers/create?name=' . $post->post_name . '-ssh', array(
			"Image" => "lscr.io/linuxserver/openssh-server",
			"Env" => [
				"PUID=33",
				"PGID=33",
				"TZ=Europe/Amsterdam",
				"SUDO_ACCESS=true", #optional
				"PASSWORD_ACCESS=true", #optional
				"USER_PASSWORD={$post->post_name}", #optional
				"USER_NAME={$post->post_name}", #optional
			],
			"Labels" => [
				"traefik.enable" => "true",
				"traefik.tcp.routers.{$post->post_name}-ssh.rule" => "HostSNI(`*`)",
				"traefik.tcp.routers.{$post->post_name}-ssh.entrypoints" => "ssh",
				"traefik.tcp.routers.users.service" => "users-service",
				"traefik.tcp.services.users-service.loadbalancer.server.port" => "2222"
			],
			"HostConfig" => [
				"NetworkMode" => "swarm",
				"PortBindings" => [ 
					"2222/tcp" => [
						[ 	
							"HostPort" => $port
						]
					]
				],
				"Binds" => [
					"/home/mklasen/halcyon-wp/users/defaults/custom-cont-init.d:/config/custom-cont-init.d",
					"/home/mklasen/halcyon-wp/users/defaults/.profile:/config/.profile",
					"/home/mklasen/halcyon-wp/users/home/{$post->post_name}/config:/config",
					"/home/mklasen/halcyon-wp/users/welcome.txt:/etc/motd",
					"/home/mklasen/halcyon-wp/users/defaults/ssh_config:/config/ssh_host_keys/sshd_config"
				 ]
			],
			"ExposedPorts" => [
				"{$port}/tcp" => (object) []
			]
		));
	}

	public function docker_request($action, $path, $args = array()) {

		$default_args = array();

		$args = array_merge($default_args, $args);

		$request = wp_remote_post(
			$this->api_url . $path,
			array(
				'body'        => wp_json_encode($args),
				'method' 	  => $action,
				'headers'     => [
					'Content-Type' => 'application/json',
				],
			)
		);
		
		error_log(print_r(wp_remote_retrieve_body($request), true));

		return $request;
	}

	public function add_details($field) {

		$field['disabled'] = true;

		$post_id = get_the_ID();
		$post = get_post($post_id);

		$hostname = $this->get_hostname($post);
		$container_ids = $this->get_container_ids($post_id);
		$port = get_field('port', $post_id);

		if (!empty($container_ids)) {

			ob_start();

			echo "<table>";
			echo "<tr><td>Host</td><td>{$hostname}</td></tr>";
			echo "<tr><td>Username</td><td>{$post->post_name}</td></tr>";
			echo "<tr><td>Password</td><td>{$post->post_name}</td></tr>";
			echo "<tr><td>Port</td><td>{$port}</td></tr>";
			echo "</table>";

			echo "<p>SSH: <i>ssh {$post->post_name}@{$hostname} -p{$port}</i></p>";

			echo '<p><a class="button button-primary button-large" target="_blank" href="https://'.$hostname.'">Visit environment</a></p>';


			$field['message'] = ob_get_clean();
				
		} else {
			$field['message'] = '<p><i>Environment has not been created yet.</i>';
		}

		return $field;
	}

	public function add_manage_buttons($field) {

		$field['disabled'] = true;

		$post_id = get_the_ID();
		$container_ids = $this->get_container_ids($post_id);

		$port = get_field('port', $post_id);

		if (is_array($container_ids) && !empty($container_ids)) {
			ob_start();
			?>
			<div class="premia-container-control">
			<p>
				<button class="button" target="_blank" data-id="<?php echo $post_id; ?>" data-action="start">Start containers</button>
				<button class="button" target="_blank" data-id="<?php echo $post_id; ?>" data-action="stop">Stop containers</button>
			</p>
			</div>
			<?php
			$field['message'] = ob_get_clean();
		} else {
			$field['message'] = '<p><i>Environment has not been created yet.</i>';
		}

		return $field;
	}

	public function set_read_only($field) {
		$field['readonly'] = true;
		return $field;
	}

	public function register_endpoints() {
		register_rest_route( 'premia/v1' , '/container', array(
			'methods' => 'POST',
			'callback' => array($this, 'start_env')
		) );
	}

	public function remove_env($post_id) {
		$container_ids = $this->get_container_ids($post_id);
		if (is_array($container_ids)) {
			foreach ($container_ids as $id) {
				$response = $this->docker_request('DELETE', '/containers/'.$id.'?force=true');

				$code = wp_remote_retrieve_response_code($response);
				$body = wp_remote_retrieve_body($response);
				$data = json_decode($body);
			}
		}

		update_field('container_ids', '', $post_id);
	}

	public function start_env($request) {
		$params = $request->get_json_params();
		$action = $params['action'];
		$post_id = $params['id'];

		$container_ids = $this->get_container_ids($post_id);

		$responses = [];

		if (is_array($container_ids)) {
			foreach ($container_ids as $id) {
				$request = $this->docker_request('POST', '/containers/'.$id.'/'.$action);
				$responses[] = $request;
			}
		}

		return $responses;
	}

	public function stop_env() {

	}
}

new Main();
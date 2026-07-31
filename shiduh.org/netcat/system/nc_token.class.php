<?php
// simple check
if ( !class_exists("nc_System") ) die("Unable to load file.");

/**
 * nc_Token class
 */
class nc_Token extends nc_System {

    protected $core;

    public function __construct() {
        // inherit
        parent::__construct();
        // get nc_core
        $this->core = nc_Core::get_object();
    }


    public function get ($user_id = 0) {
        // deprecate
        global $AUTH_USER_ID;
        // get secret key
        $key = $this->core->get_settings('SecretKey');
        // user ID
        if (!$user_id) $user_id = $AUTH_USER_ID;
        // key not setted
        if (!$key) {
            $key = $this->_make_secret_key();
            $this->core->set_settings('SecretKey', $key);
        }
		// return token value
        return $this->_make_token($user_id, $key);
    }
    
	
	private function _make_token ($user_id, $key) {
		// return token value
        return md5($user_id . $key);
	}
    
	
	private function _make_secret_key () {
		// return SecretKey value
        return md5( $this->seed() . microtime() . $this->rand() );
	}
	
	
    public function get_url ($user_id = 0) {
        return "nc_token=".$this->get($user_id);
    }
    
	
	public function get_input ($user_id = 0) {
        return "<input type='hidden' name='nc_token' value='".$this->get($user_id)."' />";
    }
    

    public function verify () {
        // deprecate
        global $AUTH_USER_ID;
        // no auth data
        if (!$AUTH_USER_ID) return true;
        // get token
        $token = $this->core->input->fetch_get_post('nc_token');
        // token not passed
        if (!$token) {
			// deprecate
            global $nc_token;
            // set global token
            $token = $nc_token;
        }
		// get secret key
        $key = $this->core->get_settings('SecretKey');

        return ( $token == $this->_make_token($AUTH_USER_ID, $key) );
    }


    public function is_use ($action) {
        if ($action == 'message' || $action == 'change') $action = 'edit';
        if ($action == 'delete') $action = 'drop';
        return $this->core->get_settings('UseToken') & constant('NC_TOKEN_'.strtoupper($action));
    }
    
	
	public function seed ($characters = 16) {
		// variables
		$seed = '';
		// get seed
		if( function_exists('openssl_random_pseudo_bytes') ) {
			$seed = openssl_random_pseudo_bytes($characters);
		}
		else {
			for ($i = 0; $i < $characters; $i++) {
				$seed .= chr( mt_rand(0, 255) );
			}
		}
		// return seed value
		return $seed;
	}
	
	
	public function rand ($min = null, $max = null) {
		// seed
		srand( $this->seed() );
		// get rand
		if ( !is_null($min) ) {
			if ( !is_null($max) ) {
				$randval = rand($min, $max);
			}
			else {
				$randval = rand($min);
			}
		}
		else {
			$randval = rand();	
		}
		// return random value
		return $randval;
	}
}
?>
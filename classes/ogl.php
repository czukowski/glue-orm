<?php defined('SYSPATH') OR die('No direct access allowed.');

class OGL {
	// Constants :
	const ASC	= 1;
	const DESC	= 2;
	const ROOT	= 3;
	const SLAVE	= 4;
	const AUTO	= 5;

	// Single OGL instance :
	protected static $instance;

	// Private constructor :
	protected function  __construct() {}

	// Get single instance :
	static protected function instance() {
		if ( ! isset(self::$instance))
			self::$instance = new self;
		return self::$instance;
	}

	public static function load($entity_name, &$set) {
		return self::instance()->_load($entity_name, $set);
	}

	protected function _load($entity_name, &$set) {
		return new OGL_Query($entity_name, $set);
	}

	public static function param($name) {
		return self::instance()->_param($name);
	}

	protected function _param($name) {
		return new OGL_Param_Set($name);
	}

	public static function bind(&$var) {
		return self::instance()->_bind($var);
	}

	protected function _bind(&$var) {
		return new OGL_Param_Bound($var);
	}
}
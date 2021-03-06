<?php
/**
 * Generate Package Loader object and register Application with the Environment
 *
 * @package  App
 */

$env->register_application('app', 'App\\Main');

// Forge and return your Application Package object
return $env->forge('Loader:Package')
	->set_path(__DIR__)
	->set_namespace('App');

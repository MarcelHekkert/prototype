<?php

/**
 * Get the currrent URI string
 *
 * @param array
 * @return string
 */
function uri($server = null) {
	if(null === $server) {
		$server = $_SERVER;
	}

	// get requested uri
	$uri = value($server, 'REQUEST_URI', '/');

	// strip query string
	if($pos = strpos($uri, '?')) {
		$uri = substr($uri, 0, $pos);
	}

	return $uri;
}

/**
 * Get a value from array
 *
 * @param array
 * @param string/integer
 * @param mixed
 * @return mixed
 */
function value($array, $key, $default = null) {
	return array_key_exists($key, $array) ? $array[$key] : $default;
}

/**
 * Get/Set options
 *
 * @param string
 * @param mixed
 * @param array
 * @param bool
 * @return mixed
 */
function option($name, $value = null, array &$storage = null, $unset = false) {
	if(null === $storage) {
		static $storage = [];
	}

	if(false === $unset and null === $value) {
		return value($storage, $name);
	}

	$storage[$name] = $value;

	if($unset) {
		unset($storage[$name]);
	}
}

/**
 * Get defined routes
 *
 * @return array
 */
function routes() {
	$routes = option('routes');

	if(null === $routes) {
		$routes = array();
	}

	return $routes;
}

/**
 * Define a route
 *
 * @param string
 * @param object
 */
function route($uri, Closure $route) {
	$routes = routes();

	$routes[$uri] = $route;

	option('routes', $routes);
}

/**
 * Match a uri with a route
 *
 * @param string
 * @return object
 */
function match($uri) {
	$routes = routes();

	if(array_key_exists($uri, $routes)) {
		option('params', []);

		return value($routes, $uri);
	}

	foreach($routes as $pattern => $route) {
		$pattern = preg_replace('#:[a-zA-Z0-9]+#', '([^/]+)', $pattern);

		if(preg_match('#^'.$pattern.'$#', $uri, $matches)) {
			option('params', array_slice($matches, 1));

			return $route;
		}
	}
}

/**
 * Render a view
 *
 * @param string
 * @param array
 * @return string
 */
function render($__file, array $vars = array()) {
	// try relative path
	if( ! is_file($__file)) {
		$__file = option('view_dir').'/'.$__file;

		if( ! is_file($__file)) {
			throw new InvalidArgumentException(sprintf('View file not found "%s"', $__file));
		}
	}

	ob_start();

	extract($vars);

	require $__file;

	return ob_get_clean();
}

/**
 * Find a view file based on the uri
 *
 * @param string
 * @return string/null
 */
function view($uri) {
	$path = option('view_dir');

	if(null === $path) {
		throw new ErrorException('Undefined view_dir, use option("view_dir", ...path)');
	}

	$it = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
	$extensions = array();

	foreach($it as $fileinfo) {
		if($fileinfo->isFile()) {
			$extensions[] = pathinfo($fileinfo->getBasename(), PATHINFO_EXTENSION);
		}
	}

	$name = str_replace('/', '-', trim($uri, '/'));

	foreach(array_unique($extensions) as $ext) {
		$file = sprintf('%s.%s', $name, $ext);

		if(is_file($path.'/'.$file)) {
			return $path.'/'.$file;
		}
	}
}

/**
 * Match the current uri with a route and call it
 */
function run() {
	$uri = uri($_SERVER);
	$route = match($uri);

	if(null === $route) {
		// try to automatically render a file from uri
		if($map = option('auto_map')) {
			// use user specified function
			if($map instanceof Closure) {
				return $map($uri);
			}

			// if we found a file render it and return
			if($file = view($uri)) {
				echo render($file);
				return true;
			}
		}

		// route and view not found
		if($error = option('error_404')) {
			http_response_code(404);
			return $error();
		}

		// no error 404 configured
		throw new ErrorException(sprintf('Route not found for "%s", use option("error_404", ...Closure)', $uri));
	}

	$params = option('params');

	return call_user_func_array($route, $params);
}

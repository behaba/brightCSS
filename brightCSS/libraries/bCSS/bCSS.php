<?php

/**
 * brightCSS
 *
 * brightCSS is a CSS compiler and preprocessor that allows you to extend
 * the CSS language easily. You can add your own properities, rules and at-rules
 * and abstract the language as much as you want.
 *
 * Requires PHP 5.1.2
 * Tested on PHP 5.3.0
 *
 * @package brightCSS
 * @author Olivier Gorzalka
 * adapted from CSScaffold by Anthony Short <anthonyshort@me.com>
 * @copyright 2009 Anthony Short. All rights reserved.
 * @license http://opensource.org/licenses/bsd-license.php  New BSD License
 * @link https://github.com/anthonyshort/csless/master
 */

class Less extends bCSS_Utils
{
	const VERSION = '2.0.0';
	
	/**
	 * The configuration for Less and all of it's modules.
	 * The config for Less itself should just be inside the
	 * config array, and module configs should be inside an array
	 * with the key as the name of the module. 
	 *
	 * @var array
	 */
	public static $config;
	
	/**
	 * CSS object for each processing phase. As Less loops
	 * through the files, it creates an new CSS object in this member 
	 * variable. It is through this variable that modules can access
	 * the current CSS string being processed
	 *
	 * @var object
	 */
	public static $css;
	
	/**
	 * The level of logged message to be thrown as an error. Setting this
	 * to 0 will mean only error-level messages are thrown. However, setting
	 * it to 1 will throw warnings as errors and halt the process.
	 *
	 * @var int
	 */
	private static $error_threshold;

	/**
	 * The final, combined output of the CSS.
	 *
	 * @var Mixed
	 */
	public static $output = null;
	
	/**
	 * Include paths
	 *
	 * These are used for finding files on the system. Rather than
	 * using PHP's built-in include paths, we just store the paths
	 * in this array and use the find_file function to locate it.
	 *
	 * @var array
	 */
	private static $include_paths = array();
	
	/**
	 * Any files that are found with find_file are stored here so that
	 * any further requestes for the files are just given the path
	 * from this array, rather than searching for the file again.
	 *
	 * @var array
	 */
	private static $find_file_paths;

	/**
	 * Parser defined. They are stored with the parser name
	 * as the key, and the path to the parser as the value.
	 *
	 * @var array
	 */
	public static $parser = array();

	/**
	 * Parser defined. They are stored with the parser name
	 * as the key, and the path to the parser as the value.
	 *
	 * @var array
	 */
	public static $parser_name = array();
		
	/**
	 * List of included modules. They are stored with the module name
	 * as the key, and the path to the module as the value. However,
	 * calling the modules method will return just the names of the modules.
	 *
	 * @var array
	 */
	public static $modules;

	/**
	 * Flags allow Less to create cache variants based on particular
	 * parameters. This could be the browser, the time etc. 
	 *
	 * @var array
	 */
	public static $flags = array();

	/**
	 * Options are used by modules to check if the user wants a paricular
	 * action to occur. They don't affect the cache, like flags do, so
	 * modules shouldn't modify the CSS string based on options. They
	 * can be used to modify the output or to perform some secondary
	 * action, like validating the CSS.
	 *
	 * @var array
	 */
	public static $options;
	
	/**
	 * If Less encounted an error. You can check this variable to
	 * see if there were any errors when in_production is set to true.
	 *
	 * @var boolean
	 */
	public static $has_error = false;

	/**
	 * Stores the headers for sending to the browser.
	 *
	 * @var array
	 */
	private static $headers;
	
	/**
	 * Parse the CSS. This takes an array of files, options and configs
	 * and parses the CSS, outputing the processed CSS string.
	 *
	 * @param array List of files
	 * @param array Configuration options
	 * @param string Options
	 * @param boolean Return the CSS rather than displaying it
	 * @return string The processed css file as a string
	 */
	public static function parse( $files, $config, $options = array(), $display = false )
	{
		# Benchmark will do the entire run from start to finish
		bCSS_Benchmark::start('system');

		try
		{			
			# Setup the cache and other variables/constants
			Less::setup($config);

			self::$options = $options;
			$css = false;
			
			# Time it takes to get the flags
			bCSS_Benchmark::start('system.flags');
			
			# Get the flags from each of the loaded modules.
			$flags = (self::$flags === false) ? array() : self::flags();
			
			# Time it takes to get the flags
			bCSS_Benchmark::stop('system.flags');
			
			# The final, combined CSS file in the cache
			foreach($files as $file) {
			  foreach((array)self::$parser_name as $parser) {
			  	if (in_array(self::get_file_extension($file),(array)(Less::$config[$parser]['extension']))) {
			      self::$parser_name = $parser;
			      Less::$config[self::$parser_name]['extension'] = self::get_file_extension($file);
			    }
			  }
			}
			
			$combined = md5(serialize(array($files,$flags))) . '.' .Less::$config[self::$parser_name]['extension'];
			
			/**
			 * Check if we should use the combined cache right now and skip unneeded processing
			 */
			if(bCSS_PRODUCTION === true AND bCSS_Cache::exists($combined) AND bCSS_Cache::is_fresh($combined))
			{
				Less::$output = bCSS_Cache::open($combined);
			}
			
			if(Less::$output === null)
			{
				# We're processing the files
				bCSS_Benchmark::start('system.check_files');
	
				foreach($files as $file)
				{
					# The time to process a single file
					bCSS_Benchmark::start('system.file.' . basename($file));

					$ext_length = strlen(Less::$config[self::$parser_name]['extension'])+1;
					
					# Make sure this file is allowed
					if(substr($file, 0, 4) == "http" OR substr($file, -$ext_length, $ext_length) != '.'.Less::$config[self::$parser_name]['extension'])
					{
						Less::error('Less cannot the requested file - ' . $file);
					}
					
					/**
					 * If there are flags, we'll include them in the filename
					 */
					if(!empty($flags))
					{
						$cached_file = dirname($file) . DIRECTORY_SEPARATOR . pathinfo($file, PATHINFO_FILENAME) . '_' . implode('_', $flags) . '.css';
					}
					else
					{
						$cached_file = str_replace('.'.Less::$config[self::$parser_name]['extension'],'.css',$file);
					}
	
					$request = Less::find_file($file, false, true);
					
					/**
					 * While not in production, we want to to always recache, so we'll fake the time
					 */
					$modified = (bCSS_PRODUCTION) ? bCSS_Cache::modified($cached_file) : 0;
		
					/**
					 * If the CSS file has been changed, or the cached version doesn't exist
					 */			
					if(!bCSS_Cache::exists($cached_file) OR $modified < filemtime($request))
					{
						bCSS_Cache::write( Less::process($request), $cached_file );
						bCSS_Cache::remove($combined);
					}
		
					$css .= bCSS_Cache::open($cached_file);
					
					# The time it's taken to process this file
					bCSS_Benchmark::stop('system.file.' . basename($file));
				}
	
				Less::$output = $css;
	
				/**
				 * If any of the files have changed we need to recache the combined
				 */
				if(!bCSS_Cache::exists($combined))
				{
					bCSS_Cache::write(self::$output,$combined);
				}
				
				# The time it takes to process the files
				bCSS_Benchmark::stop('system.check_files');
			
				/**
				 * Hook to modify what is sent to the browser
				 */
				if(bCSS_PRODUCTION === false) Less::hook('display');
			}

			/**
			 * Set the HTTP headers for the request. Less will set
			 * all the headers required to score an A grade on YSlow. This
			 * means your CSS will be sent as quickly as possible to the browser.
			 */

			$length = strlen(Less::$output);
			$modified = bCSS_Cache::modified($combined);
			$lifetime = (bCSS_PRODUCTION === true) ? $config['cache_lifetime'] : 0;
			
			Less::set_headers($modified,$lifetime,$length);

			/** 
			 * If the user wants us to render the CSS to the browser, we run this event.
			 * This will send the headers and output the processed CSS.
			 */
			if($display === true)
			{
				Less::render_css(Less::$output,$config['gzip_compression']);
			}
			
			# Benchmark will do the entire run from start to finish
			bCSS_Benchmark::stop('system');
		}
		
		/**
		 * If any errors were encountered
		 */
		catch( Exception $e )
		{
			/** 
			 * The message returned by the error 
			 */
			$message = $e->getMessage();
			
			/** 
			 * Load in the error view
			 */
			if(bCSS_PRODUCTION === false && $display === true)
			{
				Less::send_headers();
				require Less::find_file('bCSS_error.php','views');
			}
		}
		
		# Log the final execution time
		#$benchmark = bCSS_Benchmark::get('system');
		#bCSS_Log::log('Total Execution - ' . $benchmark['time']);

		# Save the logs and exit 
		bCSS_Event::run('system.shutdown');

		return self::$output;
	}

	/**
	 * Sets the initial variables, checks if we need to process the css
	 * and then sends whichever file to the browser.
	 *
	 * @return void
	 */
	public static function setup($config) 
	{
		/**
		 * Choose whether to show or hide errors
		 */
		if(bCSS_PRODUCTION === false)
		{	
			ini_set('display_errors', true);
			error_reporting(E_ALL & ~E_STRICT);
		}
		else
		{
			ini_set('display_errors', false);
			error_reporting(0);
		}
		
		/**
		 * Define contstants for system paths for easier access.
		 */
		if(!defined('bCSS_SYSPATH') && !defined('bCSS_DOCROOT'))
		{
			define('bCSS_SYSPATH', self::fix_path($config['system']));
			define('bCSS_DOCROOT', $config['document_root']);
			define('bCSS_URLPATH', str_replace(bCSS_DOCROOT, '',bCSS_SYSPATH));
		}

		/**
		 * Add include paths for finding files
		 */
		Less::add_include_path(bCSS_SYSPATH,bCSS_DOCROOT);
	
		/**
		 * Tell the cache where to save files and for how long to keep them for
		 */
		bCSS_Cache::setup( Less::fix_path($config['cache']), $config['cache_lifetime'] );
		
		/**
		 * The level at which logged messages will halt processing and be thrown as errors
		 */
		self::$error_threshold = $config['error_threshold'];

		/**
		 * Disabling flags allows for quicker processing
		 */
		if($config['disable_flags'] === true)
		{
			self::$flags = false;
		}
		
		/**
		 * Tell the log where to save it's files. Set it to automatically save the log on exit
		 */
		if($config['enable_log'] === true)
		{
			bCSS_Log::log_directory(bCSS_SYSPATH.'logs');			
			bCSS_Event::add('system.shutdown', array('bCSS_Log','save'));
		}

		/**
		 * Load the defined parser
		 */
	  if (count(Less::list_files(bCSS_SYSPATH.'parsers')) == 0) {
	    self::error("<b>Fatal Error:</b> Hey wake up, it's time to define a default parser !");
	  } else {
	    foreach(Less::list_files(bCSS_SYSPATH.'parsers') as $parser) {
	      $parser = basename($parser);
	      self::$parser_name[$parser] = $parser;
    		$parser_config = bCSS_SYSPATH.'config/' . self::$parser_name[$parser] . '.php';

    		if(file_exists($parser_config))
    		{
    			unset($config);
    			include $parser_config;				
    			self::$config[self::$parser_name[$parser]] = $config;
    		}

    		self::add_include_path(self::$parser_name[$parser]);
    		
    		if( $controller = Less::find_file('parsers/'.self::$parser_name[$parser].'/'.self::$parser_name[$parser].'.php', false, true) )
    		{
    			require_once($controller);
    			self::$parser = new self::$parser_name[$parser];
    		}
	    }
	  }


		/**
		 * Load each of the modules
		 */
		foreach(Less::list_files(bCSS_SYSPATH.'modules') as $module)
		{
			$name = basename($module);
			$module_config = bCSS_SYSPATH.'config/' . $name . '.php';
			
			if(file_exists($module_config))
			{
				unset($config);
				include $module_config;				
				self::$config[$name] = $config;
			}
			
			self::add_include_path($module);
			
			if( $controller = Less::find_file($name.'.php', false, true) )
			{
				require_once($controller);
				self::$modules[$name] = new $name;
			}
		}
		
		/**
		 * Module Initialization Hook
		 * This hook allows modules to load libraries and create events
		 * before any processing is done at all. 
		 */
		self::hook('initialize');

		/**
		 * Create the shutdown event
		 */
		bCSS_Event::add('system.shutdown', array('Less','shutdown'));
	}
	
	/**
	 * Parses the single CSS file
	 *
	 * @param $file 	The file to the parsed
	 * @return $css 	string
	 */
	public static function process($file)
	{
		/**
		 * This allows Less to find files in the directory of the CSS file
		 */
		Less::add_include_path($file);

		/** 
		 * We create a new CSS object for each file. This object
		 * allows modules to easily manipulate the CSS string.
		 * Note:Inline comments are stripped when the file is loaded.
		 */
		Less::$css = new CSS_utils($file);

		/**
		 * Import Process Hook
		 * This hook is for doing any type of importing/including in the CSS
		 */
		self::hook('import_process');
		
		/**
		 * Pre-process Hook
		 * There shouldn't be any heavy processing of the string here. Just pulling
		 * out @ rules, constants and other bits and pieces.
		 */
		self::hook('pre_process');

		/**
		 * Process Hook
		 * The main process. None of the processes should conflict in any of the modules
		 */
		self::hook('process');
			
		/**
		 * Post-process Hook
		 * After any non-standard CSS has been processed and removed. This is where
		 * the nested selectors are parsed. It's not perfectly standard CSS yet, but
		 * there shouldn't be an Less syntax left at all.
		 */
		self::hook('post_process');

		/**
		 * Formatting Hook
		 * Stylise the string, rewriting urls and other parts of the string. No heavy processing.
		 */
		self::hook('formatting_process');
		
		/**
		 * Clean up the include paths
		 */
		self::remove_include_path($file);
		
		return (string)Less::$css;
	}

	/**
	 * Sets the HTTP headers for a particular file
	 *
	 * @param $param
	 * @return return type
	 */
	private static function set_headers($modified,$lifetime,$length)
	{	
		self::$headers = array();
	
		/**
		 * Set the expires headers
		 */
		$now = $expires = time();

		// Set the expiration timestamp
		$expires += $lifetime;

		Less::header('Last-Modified',gmdate('D, d M Y H:i:s T', $now));
		Less::header('Expires',gmdate('D, d M Y H:i:s T', $expires));
		Less::header('Cache-Control','max-age='.$lifetime);
				
		/**
		 * Further caching headers
		 */
		Less::header('ETag', md5(serialize(array($length,$modified))) );
		Less::header('Content-Type','text/css');
		
		/**
		 * Content Length
		 * Sending Content-Length in CGI can result in unexpected behavior
		 */
		if(stripos(PHP_SAPI, 'cgi') === FALSE)
		{
			Less::header('Content-Length',$length);
		}
		
		/**
		 * Set the expiration headers
		 */
		if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']))
		{
			if (($strpos = strpos($_SERVER['HTTP_IF_MODIFIED_SINCE'], ';')) !== FALSE)
			{
				// IE6 and perhaps other IE versions send length too, compensate here
				$mod_time = substr($_SERVER['HTTP_IF_MODIFIED_SINCE'], 0, $strpos);
			}
			else
			{
				$mod_time = $_SERVER['HTTP_IF_MODIFIED_SINCE'];
			}

			$mod_time = strtotime($mod_time);
			$mod_time_diff = $mod_time + $lifetime - time();

			if ($mod_time_diff > 0)
			{
				// Re-send headers
				Less::header('Last-Modified', gmdate('D, d M Y H:i:s T', $mod_time) );
				Less::header('Expires', gmdate('D, d M Y H:i:s T', time() + $mod_time_diff) );
				Less::header('Cache-Control', 'max-age='.$mod_time_diff);
				Less::header('_status',304);

				// Prevent any output
				Less::$output = '';
			}
		}
	}

	/**
	 * Allows modules to hook into the processing at any point
	 *
	 * @param $method The method to check for in each of the modules
	 * @return boolean
	 */
	private static function hook($method)
	{

		if(method_exists(self::$parser,$method))
		{				
			call_user_func(array(self::$parser_name,$method));
		}
		
		foreach(self::$modules as $module_name => $module)
		{

			if(method_exists($module,$method))
			{				
				call_user_func(array($module_name,$method));
			}
		}
	}
	
	/**
	 * Renders the CSS
	 *
	 * @param $output What to display
	 * @return void
	 */
	public static function render_css($output,$level = false)
	{
		if ($level AND ini_get('output_handler') !== 'ob_gzhandler' AND (int) ini_get('zlib.output_compression') === 0)
		{
			if ($level < 1 OR $level > 9)
			{
				# Normalize the level to be an integer between 1 and 9. This
				# step must be done to prevent gzencode from triggering an error
				$level = max(1, min($level, 9));
			}

			if (stripos(@$_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== FALSE)
			{
				$compress = 'gzip';
			}
			elseif (stripos(@$_SERVER['HTTP_ACCEPT_ENCODING'], 'deflate') !== FALSE)
			{
				$compress = 'deflate';
			}
		}

		if (isset($compress) AND $level > 0)
		{
			switch ($compress)
			{
				case 'gzip':
					# Compress output using gzip
					$output = gzencode($output, $level);
				break;
				case 'deflate':
					# Compress output using zlib (HTTP deflate)
					$output = gzdeflate($output, $level);
				break;
			}

			# This header must be sent with compressed content to prevent browser caches from breaking
			Less::header('Vary','Accept-Encoding');

			# Send the content encoding header
			Less::header('Content-Encoding',$compress);
		}
	
		# Send the headers
		Less::send_headers();
	
		echo $output;
		exit;
	}
	
	/**
	 * Sends all of the stored headers to the browser
	 *
	 * @return void
	 */
	private static function send_headers()
	{
		if(!headers_sent())
		{
			self::$headers = array_unique(self::$headers);

			foreach(self::$headers as $name => $value)
			{
				if($name != '_status')
				{
					header($name . ':' . $value);
				}
				else
				{
					if($value === 304)
					{
						header('Status: 304 Not Modified', TRUE, 304);
					}
					elseif($value === 500)
					{
						header('HTTP/1.1 500 Internal Server Error');
					}
				}
			}
		}
	}

	/**
	 * Prepares the final output and cleans up
	 *
	 * @return void
	 */
	public static function shutdown()
	{
		return self::$output = array(
			'status'  => self::$has_error,
		    'content' => self::$output,
		    'headers' => self::$headers,
		    'flags'   => self::$flags,
		    'log'	  => bCSS_Log::$log,
		);
	}

	/**
	 * Displays an error and halts the parsing.
	 *	
	 * @param $message
	 * @return void
	 */
	public static function error($message)
	{
		/**
		 * Log the message before we throw the error
		 */
		bCSS_Log::log($message,0);
		
		/**
		 * Useful variable to let other objects know there was an error with the parsing
		 */
		self::$has_error = true;
		
		/**
		 * Add the error header. If the CSS is rendered, this will be sent
		 */
		self::header('_status',500);
		
		/**
		 * This will be caught in the parse method
		 */
		throw new Exception($message);
	}
	
	/**
	 * Uses the logging class to log a message
	 *
	 * @author your name
	 * @param $message
	 * @return void
	 */
	public static function log($message,$level)
	{
		if ($level <= self::$error_threshold)
		{
			self::error($message);
		}
		else
		{
			bCSS_Log::log($message,$level);
		}
	}
	
	/**
	 * Adds a new HTTP header for sending later.
	 *
	 * @author your name
	 * @param $name
	 * @param $value
	 * @return boolean
	 */
	private static function header($name,$value)
	{
		return self::$headers[$name] = $value;
	}
	
	/**
	 * Sets a cache flag
	 *
	 * @param 	$name	The name of the flag to set
	 * @return 	void
	 */
	public static function flag_set($name)
	{
		return self::$flags[] = $name;
	}
	
	/**
	 * Checks if a flag is set
	 *
	 * @param $flag
	 * @return boolean
	 */
	public static function flag($flag)
	{
		return (in_array($flag,self::$flags)) ? true : false;
	}
	
	/**
	 * Gets the flags from each of the modules
	 *
	 * @param $param
	 * @return $array The array of flags
	 */
	public static function flags()
	{		
		if(!empty(self::$flags))
			return self::$flags;
			
		self::hook('flag');

		return (isset(self::$flags)) ? self::$flags : false;
	}

	/**
	 * Get all include paths.
	 *
	 * @return  array
	 */
	public static function include_paths()
	{
		return self::$include_paths;
	}
	
	/**
	 * Adds a path to the include paths list
	 *
	 * @param 	$path 	The server path to add
	 * @return 	void
	 */
	public static function add_include_path($path)
	{
		if(func_num_args() > 1)
		{
			$args = func_get_args();

			foreach($args as $inc)
				self::add_include_path($inc);
		}
	
		if(is_file($path))
		{
			$path = dirname($path);
		}
	
		if(!in_array($path,self::$include_paths))
		{
			self::$include_paths[] = bCSS_Utils::fix_path($path);
		}
	}
	
	/**
	 * Removes an include path
	 *
	 * @param	$path 	The server path to remove
	 * @return 	void
	 */
	public static function remove_include_path($path)
	{
		if(in_array($path, self::$include_paths))
		{
			unset(self::$include_paths[array_search($path, self::$include_paths)]);
		}
	}
	
	/**
	 * Checks to see if an option is set
	 *
	 * @param $name
	 * @return boolean
	 */
	public static function option($name)
	{
		return isset(self::$options[$name]);
	}
	
	/**
	 * Loads a view file
	 *
	 * @param 	string	The name of the view
	 * @param	boolean	Render the view immediately
	 * @param	boolean Return the contents of the view
	 * @return	void	If the view is rendered
	 * @return	string	The contents of the view
	 */
	public static function view( $view, $render = false )
	{
		# Find the view file
		$view = self::find_file($view . '.php', 'views', true);
		
		# Display the view
		if ($render === true)
		{
			include $view;
			return;
		}
		
		# Return the view
		else
		{
			ob_start();
			echo file_get_contents($view);
			return ob_get_clean();
		}
	}
	
	/**
	 * Find a resource file in a given directory. Files will be located according
	 * to the order of the include paths.
	 *
	 * @throws  error  	 if file is required and not found
	 * @param   string   filename to look for
	 * @param   string   directory to search in
	 * @param   boolean  file required
	 * @return  string   if the file is found
	 * @return  FALSE    if the file is not found
	 */
	public static function find_file($filename, $directory = '', $required = FALSE)
	{		
		# Search path
		$search = $directory.DIRECTORY_SEPARATOR.$filename;
		
		if(file_exists($filename))
		{
			return self::$find_file_paths[$filename] = $filename;
		}
		elseif(file_exists($search))
		{
			return self::$find_file_paths[$search] = realpath($search);
		}
		
		if (isset(self::$find_file_paths[$search]))
			return self::$find_file_paths[$search];

		# Load include paths
		$paths = self::include_paths();

		# Nothing found, yet
		$found = NULL;

		if(in_array($directory, $paths))
		{
			if (is_file($directory.$filename))
			{
				# A matching file has been found
				$found = $search;
			}
		}
		else
		{
			foreach ($paths as $path)
			{
				if (is_file($path.$search))
				{
					# A matching file has been found
					$found = realpath($path.$search);

					# Stop searching
					break;
				}
				elseif (is_file(realpath($path.$search)))
				{
					# A matching file has been found
					$found = realpath($path.$search);

					# Stop searching
					break;
				}
			}
		}

		if ($found === NULL)
		{
			if ($required === TRUE)
			{
				# If the file is required, throw an exception
				self::error("Cannot find the file: " . str_replace($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR, $search));
			}
			else
			{
				# Nothing was found, return FALSE
				$found = FALSE;
			}
		}

		return self::$find_file_paths[$search] = $found;
	}

	/**
	 * Retrieve the extension of a file
	 *
	 * @param   string   name (and path) of the file
	 * @return  string   the extension of the file
	 */
	function get_file_extension($file_name)
  {
  	return substr(strrchr($file_name,'.'),1);
  }

	/**
	 * Lists all files and directories in a resource path.
	 *
	 * @param   string   directory to search
	 * @param   boolean  list all files to the maximum depth?
	 * @param   string   full path to search (used for recursion, *never* set this manually)
	 * @return  array    filenames and directories
	 */
	public static function list_files($directory, $recursive = FALSE, $path = FALSE)
	{
		$files = array();

		if ($path === FALSE)
		{
			if(is_dir($directory))
			{
				$files = array_merge($files, self::list_files($directory, $recursive, $directory));
			}
			else
			{
				foreach (array_reverse(self::include_paths()) as $path)
				{
					$files = array_merge($files, self::list_files($directory, $recursive, $path.$directory));
				}
			}
		}
		else
		{
			$path = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

			if (is_readable($path))
			{
				$items = (array) glob($path.'*');
				
				if ( ! empty($items))
				{
					foreach ($items as $index => $item)
					{
						$name = pathinfo($item, PATHINFO_BASENAME);
						
						if(substr($name, 0, 1) == '.' || substr($name, 0, 1) == '-')
						{
							continue;
						}
						
						$files[] = $item = str_replace('\\', DIRECTORY_SEPARATOR, $item);

						// Handle recursion
						if (is_dir($item) AND $recursive == TRUE)
						{
							// Filename should only be the basename
							$item = pathinfo($item, PATHINFO_BASENAME);

							// Append sub-directory search
							$files = array_merge($files, self::list_files($directory, TRUE, $path.$item));
						}
					}
				}
			}
		}

		return $files;
	}
}
<?php namespace Bkwld\Croppa;

/**
 * Appends and parses params of URLs
 */
class URL {

	/**
	 * The pattern used to indetify a request path as a Croppa-style URL
	 * https://github.com/BKWLD/croppa/wiki/Croppa-regex-pattern
	 *
	 * @return string
	 */
	const PATTERN = '(.+)-([0-9_]+)x([0-9_]+)(-[0-9a-zA-Z(),\-._]+)*\.(jpg|jpeg|png|gif|JPG|JPEG|PNG|GIF)$';

	/**
	 * Croppa general configuration
	 * 
	 * @var array
	 */
	private $config;

	/**
	 * Inject dependencies
	 *
	 * @param array $config 
	 */
	public function __construct($config = []) {
		$this->config = $config;
	}

	/**
	 * Insert Croppa parameter suffixes into a URL.  For use as a helper in views
	 * when rendering image src attributes.
	 *
	 * @param string $url URL of an image that should be cropped
	 * @param integer $width Target width
	 * @param integer $height Target height
	 * @param array $options Addtional Croppa options, passed as key/value pairs.  Like array('resize')
	 * @return string The new path to your thumbnail
	 */
	public function generate($url, $width = null, $height = null, $options = null) {

		// Extract the path from a URL and remove it's leading slash
		$path = $this->toPath($url);

		// Skip croppa requests for images the ignore regexp
		if (isset($this->config['ignore']) 
			&& preg_match('#'.$this->config['ignore'].'#', $path)) {
			return $this->pathToUrl($path);
		}

		// Defaults
		if (empty($path)) return; // Don't allow empty strings
		if (!$width && !$height) return $this->pathToUrl($path); // Pass through if empty
		$width = $width ? round($width) : '_';
		$height = $height ? round($height) : '_';		
		
		// Produce width, height, and options
		$suffix = '-'.$width.'x'.$height;
		if ($options && is_array($options)) {
			foreach($options as $key => $val) {
				if (is_numeric($key)) $suffix .= '-'.$val;
				elseif (is_array($val)) $suffix .= '-'.$key.'('.implode(',',$val).')';
				else $suffix .= '-'.$key.'('.$val.')';
			}
		}
		
		// Assemble the new path and return
		$parts = pathinfo($path);
		$path = trim($parts['dirname'],'/').'/'.$parts['filename'].$suffix;
		if (isset($parts['extension'])) $path .= '.'.$parts['extension'];
		return $this->pathToUrl($path);
	}

	/**
	 * Extract the path from a URL and remove it's leading slash
	 *
	 * @param string $url
	 * @return string path
	 */
	public function toPath($url) {
		return ltrim(parse_url($url, PHP_URL_PATH), '/');
	}

	/**
	 * Append host to the path if it was defined
	 *
	 * @param string $path Request path (with leading slash)
	 * @return string 
	 */
	public function pathToUrl($path) {
		if (empty($this->config['url_prefix'])) return '/'.$path;
		else if (empty($this->config['path'])) return rtrim($this->config['url_prefix'], '/').'/'.$path;
		else return rtrim($this->config['url_prefix'], '/').'/'.$this->relativePath($path);
	}

	/**
	 * Make the regex for the route definition.  This works by wrapping both the
	 * basic Croppa pattern and the `path` config in positive regex lookaheads so
	 * they working like an AND condition.
	 * https://regex101.com/r/kO6kL1/1
	 *
	 * In the Laravel router, this gets wrapped with some extra regex before the
	 * matching happnens and for the pattern to match correctly, the final .* needs
	 * to exist.  Otherwise, the lookaheads have no length and the regex fails
	 * https://regex101.com/r/xS3nQ2/1
	 *
	 * @return string 
	 */
	public function routePattern() {
		return sprintf("(?=%s)(?=%s).+", $this->config['path'], self::PATTERN);
	}

	/**
	 * Parse a request path into Croppa instructions
	 *
	 * @param string $request 
	 * @return array | boolean
	 */
	public function parse($request) {
		if (!preg_match('#'.self::PATTERN.'#', $request, $matches)) return false;
		return [
			$this->relativePath($matches[1].'.'.$matches[5]), // Path
			$matches[2] == '_' ? null : (int) $matches[2],    // Width
			$matches[3] == '_' ? null : (int) $matches[3],    // Height
			$this->options($matches[4]),                      // Options
		];
	}

	/**
	 * Take the path with Croppa options removed and get the path relative
	 * to the crops_dir 
	 *
	 * @param string $path 
	 * @return string 
	 */
	public function relativePath($path) {
		preg_match('#'.$this->config['path'].'#', $path, $matches);
		return $matches[1];
	}
	
	/**
	 * Create options array where each key is an option name
	 * and the value if an array of the passed arguments
	 * 
	 * @param  string $option_params Options string in the Croppa URL style
	 * @return array
	 */
	public function options($option_params) {
		$options = array();
		
		// These will look like: "-quadrant(T)-resize"
		$option_params = explode('-', $option_params);
		
		// Loop through the params and make the options key value pairs
		foreach($option_params as $option) {
			if (!preg_match('#(\w+)(?:\(([\w,.]+)\))?#i', $option, $matches)) continue;
			if (isset($matches[2])) $options[$matches[1]] = explode(',', $matches[2]);
			else $options[$matches[1]] = null;
		}

		// Return new options array
		return $options;
	}

	/**
	 * Take options in the URL and options from the config file and produce a
	 * config array in the format that PhpThumb expects
	 *
	 * @param array $options The url options from `parseOptions()`
	 * @return array 
	 */
	public function phpThumbConfig($options) {
		return [
			'jpegQuality' => isset($options['quality']) ? $options['quality'][0] : $this->config['jpeg_quality'],
			'interlace' => isset($options['interlace']) ? $options['interlace'][0] : $this->config['interlace'],
		];
	}

}
<?php

namespace Fuel\Kernel;

class Error
{
	/**
	 * @var  array  add names for the error levels
	 */
	public $levels = array(
		0                  => 'Error',
		E_ERROR            => 'Error',
		E_WARNING          => 'Warning',
		E_PARSE            => 'Parsing Error',
		E_NOTICE           => 'Notice',
		E_CORE_ERROR       => 'Core Error',
		E_CORE_WARNING     => 'Core Warning',
		E_COMPILE_ERROR    => 'Compile Error',
		E_COMPILE_WARNING  => 'Compile Warning',
		E_USER_ERROR       => 'User Error',
		E_USER_WARNING     => 'User Warning',
		E_USER_NOTICE      => 'User Notice',
		E_STRICT           => 'Runtime Notice'
	);

	/**
	 * @var  array  types of error that are considered fatal
	 */
	public $fatal_levels = array(
		E_PARSE,
		E_ERROR,
		E_USER_ERROR,
		E_COMPILE_ERROR
	);

	/**
	 * @var  int  number of errors handled
	 */
	public $count = 0;

	/**
	 * @var  array  Non fatal errors thrown before a fatal error occurred
	 */
	public $non_fatal_cache = array();

	/**
	 * Native PHP shutdown handler
	 *
	 * @return  string
	 */
	public function shutdown_handler()
	{
		$error = error_get_last();

		// Only show valid fatal errors
		if ($error and in_array($error['type'], $this->fatal_levels))
		{
			$error = new \ErrorException($error['message'], $error['type'], 0, $error['file'], $error['line']);
			$this->show_error($error);
			exit(1);
		}
	}

	/**
	 * PHP Exception handler
	 *
	 * @param   \Exception  $e  the exception
	 * @return  bool
	 */
	public function exception_handler(\Exception $e)
	{
		if (method_exists($e, 'handle'))
		{
			return $e->handle();
		}
		static::show_error($e);
	}

	/**
	 * PHP Error handler
	 *
	 * @param   int     $severity  the severity code
	 * @param   string  $message   the error message
	 * @param   string  $filepath  the path to the file throwing the error
	 * @param   int     $line      the line number of the error
	 * @return  bool    whether to continue with execution
	 */
	public function error_handler($severity, $message, $filepath, $line)
	{
		// Increase error counter
		$this->count++;

		if ($this->count <= _env()->config->get('errors.throttling', 10))
		{
			$this->show_error(new \ErrorException($message, $severity, 0, $filepath, $line));
		}
		elseif ($this->count == (_env('config')->get('error_throttling', 10) + 1)
				and ($severity & error_reporting()) == $severity)
		{
			$this->show_error(new \ErrorException('Error throttling threshold was reached, no more full error reports are shown.', E_USER_NOTICE));
		}

		return true;
	}

	/**
	 * Shows an error. It will stop script execution if the error code is not
	 * in the errors.continue_on whitelist.
	 *
	 * @param   \Exception  $e  the exception to show
	 * @return  void
	 */
	public static function show_error(\Exception $e)
	{
		$fatal = (bool)( ! in_array($e->getCode(), _env('config')->get('errors.continue_on', array())));
		$data = $this->prepare_exception($e, $fatal);

		if ($fatal)
		{
			$data['contents'] = ob_get_contents();
			while (ob_get_level() > 0)
			{
				ob_end_clean();
			}
			ob_start(_env()->config->get('ob_callback', null));
		}
		else
		{
			$this->non_fatal_cache[] = $data;
		}

		if (_env('input')->is_cli())
		{
			$cli = _env()->get_object('Cli');
			$cli->write($cli->color($data['severity'].' - '.$data['message'].' in '.$data['filepath'].' on line '.$data['error_line'], 'red'));
			$fatal and exit(1);
			return;
		}

		if ($fatal)
		{
			if ( ! headers_sent())
			{
				$protocol = _env('input')->server('SERVER_PROTOCOL')
					? _env('input')->server('SERVER_PROTOCOL')
					: 'HTTP/1.1';
				header($protocol.' 500 Internal Server Error');
			}

			$data['non_fatal'] = $this->non_fatal_cache;

			try
			{
				exit(_forge('View', _env('config')->get('errors.view_fatal'), $data, false));
			}
			catch (\Exception $e)
			{
				exit($data['severity'].' - '.$data['message'].' in '.$data['filepath'].' on line '.$data['error_line']);
			}
		}

		try
		{
			echo _forge('View', _env('config')->get('errors.view_error'), $data, false);
		}
		catch (\Exception $e)
		{
			echo $e->getMessage().'<br />';
		}
	}

	protected function prepare_exception(\Exception $e, $fatal = true)
	{
		// Convert exception to data array for error View
		$data = array();
		$data['type']        = get_class($e);
		$data['severity']    = $e->getCode();
		$data['message']     = $e->getMessage();
		$data['filepath']    = $e->getFile();
		$data['error_line']  = $e->getLine();
		$data['backtrace']   = $e->getTrace();

		// Translate severity int to string
		$data['severity'] = ( ! isset($this->levels[$data['severity']]))
			? $data['severity']
			: $this->levels[$data['severity']];

		// Unset unnecessary backtrace entries
		foreach ($data['backtrace'] as $key => $trace)
		{
			if ( ! isset($trace['file']))
			{
				unset($data['backtrace'][$key]);
			}
			elseif ($trace['file'] == __FILE__)
			{
				unset($data['backtrace'][$key]);
			}
		}

		// @todo implement the commented out lines
		// $data['debug_lines'] = \Debug::file_lines($data['filepath'], $data['error_line'], $fatal);
		$data['orig_filepath'] = $data['filepath'];
		// $data['filepath'] = \Fuel::clean_path($data['filepath']);
		$data['filepath'] = str_replace("\\", "/", $data['filepath']);

		return $data;
	}
}
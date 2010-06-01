<?php

namespace mageekguy\tests\unit;

use mageekguy\tests\unit;
use mageekguy\tests\unit\asserter;

abstract class test implements observable
{
	const version = '$Rev$';
	const author = 'Frédéric Hardy';
	const testMethodPrefix = 'test';

	const startRun = 1;
	const beforeSetUp = 2;
	const afterSetUp = 3;
	const beforeTestMethod = 4;
	const afterTestMethod = 5;
	const beforeTearDown = 6;
	const afterTearDown = 7;
	const endRun = 8;

	protected $score = null;
	protected $assert = null;
	protected $observers = array();

	private $class = '';
	private $path = '';
	private $testMethods = array();
	private $currentMethod = null;

	public function __construct()
	{
		$this->score = new unit\score();
		$this->assert = new unit\asserter($this->score);

		$class = new \reflectionClass($this);

		$this->class = $class->getName();

		$this->path = $class->getFilename();

		foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $publicMethod)
		{
			$methodName = $publicMethod->getName();

			if (strpos($methodName, self::testMethodPrefix) === 0)
			{
				$this->testMethods[] = $methodName;
			}
		}
	}

	public function addObserver(observer $observer)
	{
		$this->observers[] = $observer;
		return $this;
	}

	public function sendEventToObservers($event)
	{
		foreach ($this->observers as $observer)
		{
			$observer->manageObservableEvent($this, $event);
		}

		return $this;
	}

	public function getClass()
	{
		return $this->class;
	}

	public function getPath()
	{
		return $this->path;
	}

	public function getScore()
	{
		return $this->score;
	}

	public function getVersion()
	{
		return substr(self::version, 6, -2);
	}

	public function getTestMethods()
	{
		return $this->testMethods;
	}

	public function run(array $testMethods = array(), $runInChildProcess = true)
	{
		$this->sendEventToObservers(self::startRun);

		if (sizeof($testMethods) <= 0)
		{
			$testMethods = $this->testMethods;
		}

		try
		{
			if ($runInChildProcess === true)
			{
				$this->sendEventToObservers(self::beforeSetUp);
				$this->setUp();
				$this->sendEventToObservers(self::afterSetUp);
			}

			foreach ($testMethods as $testMethod)
			{
				if (in_array($testMethod, $this->testMethods) === false)
				{
					throw new \runtimeException('Test method ' . $this->getClass() . '::' . $testMethod . '() is undefined');
				}


				if ($runInChildProcess === false)
				{
					$this->runTestMethod($testMethod);
				}
				else
				{
					$this->sendEventToObservers(self::beforeTestMethod);
					$this->runInChildProcess($testMethod);
					$this->sendEventToObservers(self::afterTestMethod);
				}
			}

			if ($runInChildProcess === true)
			{
				$this->sendEventToObservers(self::beforeTearDown);
				$this->tearDown();
				$this->sendEventToObservers(self::afterTearDown);
			}
		}
		catch (\exception $exception)
		{
			$this->tearDown();
			throw $exception;
		}

		$this->sendEventToObservers(self::endRun);

		return $this;
	}

	public function errorHandler($errno, $errstr, $errfile, $errline, $context)
	{
		if (error_reporting() !== 0)
		{
			list($file, $line, $class, $method) = $this->getBacktrace();
			$this->score->addError($file, $line, $class, $method, $errno, $errstr);
		}

		return true;
	}

	protected function setUp()
	{
		return $this;
	}

	protected function runTestMethod($testMethod)
	{
		$this->currentMethod = $testMethod;

		set_error_handler(array($this, 'errorHandler'));

		try
		{
			$this->{$testMethod}();
		}
		catch (asserter\exception $exception)
		{
			# Do nothing, just break execution of current method because an assertion failed.
		}
		catch (\exception $exception)
		{
			list($file, $line, $class, $method) = $this->getBacktrace();
			$this->score->addException($file, $line, $class, $method, $exception);
		}

		restore_error_handler();

		$this->currentMethod = null;

		return $this;
	}

	protected function runInChildProcess($testMethod)
	{
		$phpCode = '<?php define(\'' . __NAMESPACE__ . '\autorun\', false); require(\'' . $this->getPath() . '\'); $unit = new ' . $this->getClass() . '; $unit->run(array(\'' . $testMethod . '\'), false); echo serialize($unit->getScore()); ?>';

		$descriptors = array
			(
				0 => array('pipe', 'r'),
				1 => array('pipe', 'w'),
				2 => array('pipe', 'w')
			);

		$php = proc_open($_SERVER['_'], $descriptors, $pipes);

		if ($php !== false)
		{
			fwrite($pipes[0], $phpCode);
			fclose($pipes[0]);

			$stdOut = stream_get_contents($pipes[1]);
			fclose($pipes[1]);

			$stdErr = stream_get_contents($pipes[2]);
			fclose($pipes[2]);

			$returnValue = proc_close($php);

			if ($stdErr != '')
			{
				throw new \runtimeException($stdErr, $returnValue);
			}

			$score = unserialize($stdOut);

			if ($score instanceof \mageekguy\tests\unit\score === false)
			{
				throw new \runtimeException('Unable to retrieve score from \'' . $stdOut . '\'');
			}

			$this->score->merge($score);
		}
	}

	protected function tearDown()
	{
		return $this;
	}

	protected function getBacktrace()
	{
		$debugBacktrace = debug_backtrace();

		foreach ($debugBacktrace as $key => $value)
		{
			if (isset($value['object']) === true && isset($value['function']) === true && $value['object'] === $this && $value['function'] === $this->currentMethod)
			{
				return array(
					$debugBacktrace[$key - 1]['file'],
					$debugBacktrace[$key - 1]['line'],
					$value['class'],
					$value['function']
				);
			}
		}

		return null;
	}
}

?>

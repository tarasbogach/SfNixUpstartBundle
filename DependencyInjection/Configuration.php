<?php

namespace SfNix\UpstartBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\UnsetKeyException;

/**
 * This is the class that validates and merges configuration from your app/config files.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/configuration.html}
 */
class Configuration implements ConfigurationInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function getConfigTreeBuilder()
	{
		$treeBuilder = new TreeBuilder();
		$rootNode = $treeBuilder->root('upstart')->example([
			'project'=>'imaging',
			'default'=>[
				'verbose'=>1,
				'native'=>[
					'setuid'=>'www-data',
				],
			],
			'job'=>[
				'imageResizer'=>[
					'command'=>'rabbitmq:consumer imageResizer -w',
					'quantity'=>10,
				],
				'faceRecognizer'=>[
					'native'=>[
						'exec'=>'python faceRecognizer.py',
						'killSignal'=>'SIGKILL',
					],
					'quantity'=>5,
				],
			]
		]);
		$rootChildren = $rootNode->children();
		$rootChildren
			->scalarNode('project')
				->info('Project name will be used as a directory name for upstart config files, and as a prefix for event names.')
				->example('sfnix')
				->isRequired()
			->end()
			->scalarNode('configDir')
				->info('Root directory of upstart config files.')
				->defaultValue('/etc/init')
			->end()
		;
		$defaultChildren = $rootChildren
			->arrayNode('default')
			->info('Defaults for all jobs.')
				->children()
		;
		$defaultChildren
			->booleanNode('debug')
				->info('Symfony command debug option (debug: 0 -> --no-debug). It will be ignored if job has no "command".')
			->end()
			->integerNode('verbose')
				->info('Symfony command verbose level option (verbose: 0 -> no options, verbose: 3 -> -vvv). It will be ignored if job has no "command".')
				->min(1)->max(3)
			->end()
			->scalarNode('env')
				->info('Symfony command env option (env: prod -> --env prod) It will be ignored if job has no "command".')
				->defaultValue('dev')
			->end()
			->scalarNode('logDir')
				->info(<<<INFO
If you use any output redirection for the script,
this option can help you tell the bundle where the log directory is.
INFO
				)
				->defaultValue('/var/log/upstart')
			->end()
		;
		$nativeChildren = $defaultChildren
			->arrayNode('native')
				->validate()->always(function($v){
					foreach(['env','export','normalExit','cgroup','limit','emits','respawnLimit'] as $key){
						if(isset($v[$key]) && !$v[$key]){
							unset($v[$key]);
						}
					}
					return $v;
				})->end()
				->info('Native upstart stanzas. They always will overwrite any stanzas generated by this bundle.')
				->children()
		;
		$this->appendNativeToDefaults($nativeChildren);
		$nativeChildren->end()->end();
		$defaultChildren->end()->end();
		$jobPrototypeChildren = $rootChildren
			->arrayNode('job')
				->info('List of jobs.')
				->example([
					'imageResizer'=>[
						'command'=>'rabbitmq:consumer imageResizer -w',
						'quantity'=>10,
					],
					'faceRecognizer'=>[
						'native'=>[
							'exec'=>'python faceRecognizer.py',
							'killSignal'=>'SIGKILL',
						],
						'quantity'=>5,
					],
				])
				->prototype('array')
					->validate()->always(function($v){
						if(isset($v['native']) && !$v['native']){
							unset($v['native']);
						}
						return $v;
					})->end()
					->children()
						->scalarNode('name')
							->info('Name of a job. Will be used as upstart config file name and log file name.')
						->end()
						->arrayNode('tag')
							->defaultValue([])
							->info('Tags.')
							->prototype('scalar')->end()
						->end()
						->scalarNode('quantity')
							->info('Number of job instances to run.')
							->defaultValue(1)
						->end()
						->scalarNode('command')
							->info('Symfony command.')
							->example('rabbitmq:consumer imageResizer -w')
						->end()
						->booleanNode('debug')
							->info('Symfony command debug option (debug: 0 -> --no-debug)')
						->end()
						->integerNode('verbose')
							->info('Symfony command verbose level option (verbose: 0 -> no options, verbose: 3 -> -vvv)')
							->min(1)->max(3)
						->end()
						->scalarNode('env')
							->info('Symfony command env option (env: prod -> --env prod)')
						->end()
						->scalarNode('script')
							->info(<<<INFO
Run some shell script, not a symfony command.
This is a shortcut for native:{exec:"..."}, or native:{script:"..."}.
INFO
							)
							->example('php bin/websocket-server.php')
						->end()
						->scalarNode('logDir')
							->info(<<<INFO
If you use any output redirection for the script,
this option can help you tell the bundle where the log directory is.
INFO
							)
						->end()
						->scalarNode('log')
							->info(<<<INFO
If you use any output redirection for the script,
this option can help you tell the bundle what is log file base name.
INFO
							)
							->validate()->ifNull()->thenUnset()->end()
						->end()
		;
		$jobNativeChildren = $jobPrototypeChildren
			->arrayNode('native')
				->validate()->always(function($v){
					foreach(['env','export','normalExit','cgroup','limit','emits','respawnLimit'] as $key){
						if(isset($v[$key]) && !$v[$key]){
							unset($v[$key]);
						}
					}
					return $v;
				})->end()
				->info('Native upstart stanzas. They always will overwrite any stanzas generated by this bundle.')
				->children()
		;
		$this->appendNativeToJob($jobNativeChildren);
		$jobNativeChildren->end()->end();
		$jobPrototypeChildren->end()->end()->end();
		$rootChildren->end();
		return $treeBuilder;
	}

	/**
	 * @param NodeBuilder $node
	 * @return NodeBuilder
	 */
	protected function appendProcessDefinitionTo(NodeBuilder $node){
		return $node
			#Process Definition
			->scalarNode('exec')
				->info('Stanza that allows the specification of a single-line command to run.')
				->example('/usr/bin/my-daemon --option foo -v')
				->validate()->ifNull()->thenUnset()->end()
			->end()
			->scalarNode('preStart')
				->info('Use this stanza to prepare the environment for the job.')
				->example('[ -d "/var/cache/squid" ] || squid -k')
				->validate()->ifNull()->thenUnset()->end()
			->end()
			->scalarNode('postStart')
				->info('Script or process to run after the main process has been spawned, but before the started(7) event has been emitted.')
				->example('while ! mysqladmin ping localhost ; do sleep 1 ; done')
				->validate()->ifNull()->thenUnset()->end()
			->end()
			->scalarNode('preStop')
				->info('The pre-stop stanza will be executed before the job\'s stopping(7) event is emitted and before the main process is killed.')
				->example('/some/directory/script')
				->validate()->ifNull()->thenUnset()->end()
			->end()
			->scalarNode('postStop')
				->info(<<<INFO
	There are times where the cleanup done in pre-start is not enough.
	Ultimately, the cleanup should be done both pre-start and post-stop,
	to ensure the service starts with a consistent environment,
	and does not leave behind anything that it shouldn.
INFO
				)
				->example('/some/directory/script')
				->validate()->ifNull()->thenUnset()->end()
			->end()
			->scalarNode('script')
				->info('Allows the specification of a multi-line block of shell code to be executed. Block is terminated by end script.')
				->example('/some/directory/script >> /var/log/some-log.log')
				->validate()->ifNull()->thenUnset()->end()
			->end()
		;
	}

	/**
	 * @param NodeBuilder $node
	 * @return NodeBuilder
	 */
	protected function appendInstancesTo(NodeBuilder $node){
		return $node
			#Instances
			->scalarNode('instance')
				->info(<<<INFO
	Sometimes you want to run the same job, but with different arguments.
	The variable that defines the unique instance of this job is defined with instance.
INFO
				)
				->validate()->ifNull()->thenUnset()->end()
			->end();
	}
	
	/**
	 * @param NodeBuilder $node
	 * @return NodeBuilder
	 */
	protected function appendDocumentationTo(NodeBuilder $node){
		return $node
			#Documentation
			->scalarNode('author')
				->info('Quoted name (and maybe contact details) of author of this Job Configuration File.')
			->validate()->ifNull()->thenUnset()->end()
			->end()
			->scalarNode('description')
				->info('One line quoted description of Job Configuration File.')
				->validate()->ifNull()->thenUnset()->end()
			->end()
			->arrayNode('emits')
				->info(<<<INFO
Specifies the events the job configuration file generates (directly or indirectly via a child process).
This stanza can be specified multiple times for each event emitted.
This stanza can also use the following shell wildcard meta-characters to simplify the specification:
 - asterisk ("*")
 - question mark ("?")
 - square brackets ("[" and "]")
INFO
				)
				->example(['*-device-*', 'foo-event', 'bar-event'])
				->prototype('scalar')->end()
			->end()
			->scalarNode('version')
				->info(<<<INFO
This stanza may contain version information about the job, such as revision control or package version number.
It is not used or interpreted by init(8) in any way.
INFO
				)
				->validate()->ifNull()->thenUnset()->end()
			->end()
			->scalarNode('usage')
				->info(<<<INFO
Brief message explaining how to start the job in question.
Most useful for instance jobs which require environment variable parameters to be specified before they can be started.
INFO
				)
				->validate()->ifNull()->thenUnset()->end()
			->end()
			;
	}

	/**
	 * @param NodeBuilder $node
	 * @return NodeBuilder
	 */
	protected function appendNativeToJob(NodeBuilder $node){
		$this->appendEventDefinitionTo($node);
		$this->appendJobEnvironmentTo($node);
		$this->appendTasksTo($node);
		$this->appendProcessEnvironmentTo($node);
		$this->appendProcessControlTo($node);
		$this->appendProcessDefinitionTo($node);
		$this->appendDocumentationTo($node);
		$this->appendInstancesTo($node);
		return $node;
	}

	/**
	 * @param NodeBuilder $node
	 * @return NodeBuilder
	 */
	protected function appendProcessControlTo(NodeBuilder $node){
		return $node
			#Process Control
			->enumNode('expect')
				->info(<<<INFO
fork - Upstart will expect the process executed to call fork(2) exactly once.
daemon - Upstart will expect the process executed to call fork(2) exactly twice.
stop  - Specifies that the job's main process will raise the SIGSTOP signal to indicate that it is ready.
init(8) will wait for this signal and then:
 - Immediately send the process SIGCONT to allow it to continue.
 - Run the job's post-start script (if any).
Only then will Upstart consider the job to be running.
INFO
				)
				->values(['fork', 'deamon', 'stop'])
				->validate()->ifNull()->thenUnset()->end()
			->end()
			->enumNode('killSignal')
				->info('Specifies the stopping signal, SIGTERM by default, a job\'s main process will receive when stopping the running job.')
				->values([
					'SIGHUP','SIGINT','SIGQUIT','SIGILL','SIGTRAP',
					'SIGIOT','SIGBUS','SIGFPE','SIGKILL','SIGUSR1',
					'SIGSEGV','SIGUSR2','SIGPIPE','SIGALRM','SIGTERM',
					'SIGSTKFLT','SIGCHLD','SIGCONT','SIGSTOP','SIGTSTP',
					'SIGTTIN','SIGTTOU','SIGURG','SIGXCPU','SIGXFSZ',
					'SIGVTALRM','SIGPROF','SIGWINCH','SIGIO','SIGPWR',
				])
			->end()
			->integerNode('killTimeout')
				->info('The number of seconds Upstart will wait before killing a process. The default is 5 seconds.')
				->min(1)
			->end()
			->enumNode('reloadSignal')
				->info('Specifies the signal that Upstart will send to the jobs main process when the job needs to be reloaded (the default is SIGHUP).')
				->values([
					'SIGHUP','SIGINT','SIGQUIT','SIGILL','SIGTRAP',
					'SIGIOT','SIGBUS','SIGFPE','SIGKILL','SIGUSR1',
					'SIGSEGV','SIGUSR2','SIGPIPE','SIGALRM','SIGTERM',
					'SIGSTKFLT','SIGCHLD','SIGCONT','SIGSTOP','SIGTSTP',
					'SIGTTIN','SIGTTOU','SIGURG','SIGXCPU','SIGXFSZ',
					'SIGVTALRM','SIGPROF','SIGWINCH','SIGIO','SIGPWR',
				])
			->end()
		;
	}

	/**
	 * @param NodeBuilder $node
	 * @return NodeBuilder
	 */
	protected function appendEventDefinitionTo(NodeBuilder $node){
		#Event Definition
		return $node
			->scalarNode('manual')
				->info('This stanza will tell Upstart to ignore the start on / stop on stanzas.')
				->example(true)
				->validate()->ifNull()->thenUnset()->end()
			->end()
			->scalarNode('startOn')
				->info(<<<INFO
	This stanza defines the set of Events that will cause the Job to be automatically started.
	Syntax: EVENT [[KEY=]VALUE]... [and|or...]
INFO
				)
				->example('event1 and runlevel [2345] and (local-filesystems and net-device-up IFACE!=lo)')
				->validate()->ifNull()->thenUnset()->end()
			->end()
			->scalarNode('stopOn')
				->info(<<<INFO
	This stanza defines the set of Events that will cause the Job to be automatically stopped if it is already running.
	Syntax: EVENT [[KEY=]VALUE]... [and|or...]
INFO
				)
				->example('runlevel [016] or event2')
				->validate()->ifNull()->thenUnset()->end()
			->end()
		;
	}
	
	protected function appendJobEnvironmentTo(NodeBuilder $node){
		return $node
			#Job Environment
			->arrayNode('env')
				->info('Allows an environment variable to be set which is accessible in all script sections.')
				->example([
					'myVar1'=>'Hello world!',
					'myVar2'=>'Goodby world!',
				])
				->prototype('scalar')->end()
			->end()
			->arrayNode('export')
				->info('Export variables previously set with env to all events that result from this job.')
				->example(['myVar1', 'myVar2',])
				->prototype('scalar')->end()
			->end();
	}

	protected function intOrUnlim(){
		return function ($v) {
			if(is_int($v) || $v=='unlimited'){
				return $v;
			}else{
				throw new \InvalidArgumentException(sprintf(
					'Value can be int or "unlimited", but %s was passed.',
					json_encode($v)
				));
			}
		};
	}
	/**
	 * @param NodeBuilder $node
	 * @return NodeBuilder
	 */
	protected function appendTasksTo(NodeBuilder $node){
		return $node
			#Services, tasks and respawning
			->arrayNode('normalExit')
				->info(<<<INFO
	Used to change Upstart\'s idea of what a "normal" exit status is.
	Conventionally, processes exit with status 0 (zero) to denote success and non-zero to denote failure.
INFO
				)
				->example([0, 13, 'SIGUSR1', 'SIGWINCH'])
				->prototype('scalar')->end()
			->end()
			->scalarNode('respawn')
				->info(<<<INFO
	With this stanza, whenever the main script/exec exits,
	without the goal of the job having been changed to stop,
	the job will be started again.
	This includes running pre-start, post-start and post-stop.
	Note that pre-stop will not be run.
INFO
				)
				->example(true)
				->validate()->ifNull()->thenUnset()->end()
			->end()
			->arrayNode('respawnLimit')
				->prototype('scalar')->end()
				->info(<<<INFO
	Syntax: [int \$COUNT, int \$INTERVAL] | ["unlimited"]
	Respawning is subject to a limit.
	If the job is respawned more than COUNT times in INTERVAL seconds,
	it will be considered to be having deeper problems and will be stopped.
INFO
				)
				->validate()
				->always()
				->then(function ($v) {
					if(!$v){
						return [10, 5];
					}
					if(
						(count($v)==1 && $v=='unlimited') ||
						(
							count($v)==2 &&
							is_int($v[0]) && $v[0] > 0 &&
							is_int($v[1]) && $v[1] > 0
						)
					){
						return $v;
					}else{
						throw new \InvalidArgumentException(sprintf(
							'Value can be ["unlimited"] or [int $COUNT, int $INTERVAL], but %s was passed.',
							json_encode($v)
						));
					}
				})
				->end()
			->end()

			->scalarNode('task')
				->info(<<<INFO
	In concept, a task is just a short lived job.
	In practice, this is accomplished by changing how the transition from a goal of "stop" to "start" is handled.

	Without the 'task' keyword, the events that cause the job to start will be unblocked as soon as the job is started.
	This means the job has emitted a starting(7) event, run its pre-start, begun its script/exec, and post-start,
	and emitted its started(7) event.

	With task, the events that lead to this job starting will be blocked until the job has completely transitioned back to stopped.
	This means that the job has run up to the previously mentioned started(7) event, and has also completed its post-stop,
	and emitted its stopped(7) event.
INFO
				)
				->example(true)
				->validate()->ifNull()->thenUnset()->end()
			->end()
			;
	}

	/**
	 * @param NodeBuilder $node
	 * @return NodeBuilder
	 */
	protected function appendProcessEnvironmentTo(NodeBuilder $node){
		return $node
			#Process environment
			->scalarNode('apparmorLoad')
				->info(<<<INFO
Load specified AppArmor Mandatory Access Control system profile into the kernel prior to starting the job.
The main job process (as specified by exec or script) will be confined to this profile.
Notes:
 - <profile-path> must be an absolute path.
 - The job will fail if the profile doesn't exist, or the profile fails to load.
INFO
				)
				->validate()->ifNull()->thenUnset()->end()
			->end()
			->scalarNode('apparmorSwitch')
				->info(<<<INFO
Run main job process with already-loaded AppArmor Mandatory Access Control system profile.
Notes:
 - The job will fail if the profile named does not exist, or is not already loaded.
INFO
				)
				->validate()->ifNull()->thenUnset()->end()
			->end()
			->arrayNode('cgroup')
				->info(<<<INFO
Upstart 1.13 supports cgroups with the aid of cgmanager (see cgmanager(8)).
A new "cgroup" stanza is introduced that allows job processes to be run within the specified cgroup.
http://upstart.ubuntu.com/cookbook/#cgroup
INFO
				)
				->example([
					['cgroup', 'cpu'],
					['memory', '$UPSTART_CGROUP', 'limit_in_bytes', 52428800],
				])
				->prototype('array')->end()
			->end()
			->enumNode('console')
				->values(['logged', 'output', 'owner', 'none'])
			->end()
			->scalarNode('chdir')
				->info('Runs the job\'s processes with a working directory in the specified directory instead of the root of the filesystem.')
				->validate()->ifNull()->thenUnset()->end()
			->end()
			->scalarNode('chroot')
				->info('Runs the job\'s processes in a chroot(8) environment underneath the specified directory.')
				->validate()->ifNull()->thenUnset()->end()
			->end()
			->arrayNode('limit')
				->info('Provides the ability to specify resource limits for a job.')
				->prototype('array')
					->children()
						->enumNode(0)->values([
							'core', 'cpu', 'data', 'fsize', 'memlock', 'msgqueue',
							'nice', 'nofile', 'nproc', 'rss', 'rtprio', 'sigpending',
							'stack'
						])->end()
						->scalarNode(1)
							->defaultValue('unlimited')
							->validate()->always($this->intOrUnlim())->end()
						->end()
						->scalarNode(2)
							->defaultValue('unlimited')
							->validate()->always($this->intOrUnlim())->end()
						->end()
					->end()
				->end()
			->end()
			->scalarNode('nice')
				->info('Change the jobs scheduling priority from the default. See nice(1).')
				->validate()
				->always()
				->then(function ($v) {
					if(is_null($v)){
						throw new UnsetKeyException('Unsetting key');
					}elseif(is_int($v) && -20 <= $v && $v <= 19){
						return $v;
					}else{
						throw new \InvalidArgumentException(sprintf(
							'Value can be -20 <= int <= 19.',
							json_encode($v)
						));
					}
				})
				->end()
			->end()
			->scalarNode('oomScore')
				->info(<<<INFO
Linux has an "Out of Memory" killer facility.
Normally the OOM killer regards all processes equally, this stanza advises the kernel to treat this job differently.
The "adjustment" value provided to this stanza may be an integer value from -999 (very unlikely to be killed by the OOM killer)
up to 1000 (very likely to be killed by the OOM killer).
It may also be the special value never to have the job ignored by the OOM killer entirely
(potentially dangerous unless you really trust the application in all possible system scenarios).
INFO
				)
				->validate()
				->always()
				->then(function ($v) {
					if(is_null($v)){
						throw new UnsetKeyException('Unsetting key');
					}elseif(is_int($v) && -999 <= $v && $v <= 1000){
						return $v;
					}elseif($v=='never'){
						return $v;
					}else{
						throw new \InvalidArgumentException(sprintf(
							'Value can be -999 <= int <= 1000 or "never", but %s was passed.',
							json_encode($v)
						));
					}
				})
				->end()
			->end()
			->scalarNode('setgid')
				->info('Changes to the group before running the job\'s process.')
				->validate()->ifNull()->thenUnset()->end()
			->end()
			->scalarNode('setuid')
				->info('Changes to the user before running the job\'s process.')
				->validate()->ifNull()->thenUnset()->end()
			->end()
			->scalarNode('umask')
				->info('Set the file mode creation mask for the process. Value should be an octal value for the mask. See umask(2) for more details.')
				->validate()->ifNull()->thenUnset()->end()
			->end();
	}
	/**
	 * @param NodeBuilder $node
	 * @return NodeBuilder
	 */
	protected function appendNativeToDefaults(NodeBuilder $node){
		$this->appendEventDefinitionTo($node);
		$this->appendJobEnvironmentTo($node);
		$this->appendTasksTo($node);
		$this->appendProcessEnvironmentTo($node);
		$this->appendProcessControlTo($node);
		return $node
			#Documentation
			->scalarNode('author')
				->info('Quoted name (and maybe contact details) of author of this Job Configuration File.')
			->validate()->ifNull()->thenUnset()->end()
		->end();
	}
}
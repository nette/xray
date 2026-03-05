<?php declare(strict_types=1);

use Nette\Xray\Collector;
use Nette\Xray\PhpAnalyzer;
use PHPStan\DependencyInjection\ContainerFactory;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$fixture = __DIR__ . '/fixtures/sample.php';
$tempDir = sys_get_temp_dir() . '/nette-xray-test-' . getmypid();
@mkdir($tempDir);

$dynamicConfig = $tempDir . '/dynamic-config.neon';
file_put_contents($dynamicConfig, sprintf(
	"services:\n\tphpAnalyzer:\n\t\targuments:\n\t\t\thasTester: true\n\t\t\thasTracy: true\n\tapp:\n\t\targuments:\n\t\t\tcwd: %s\n",
	json_encode($tempDir),
));

$containerFactory = new ContainerFactory(dirname($fixture));
$container = $containerFactory->create($tempDir, [$dynamicConfig, __DIR__ . '/../config.neon'], [$fixture]);

$analyzer = $container->getByType(PhpAnalyzer::class);
$collector = new Collector;
$analyzer->analyze([$fixture], $collector);

@unlink($dynamicConfig);
@rmdir($tempDir);

$php = json_decode(json_encode($collector->php), true);


// Static calls — return used, return discarded, named args, spread
Assert::same(1, $php['staticCalls']['Nette\Utils\Strings::trim']['returnUsed']);
Assert::same(1, $php['staticCalls']['Nette\Utils\Strings::contains']['returnDiscarded']);
Assert::same(1, $php['staticCalls']['Nette\Utils\Strings::padLeft']['args']['named']['length']);
Assert::true(isset($php['staticCalls']['Nette\Utils\Strings::match']['args']['spread']));

// First-class callable — tracked separately, not in staticCalls
Assert::same(1, $php['callableReferences']['Nette\Utils\Strings::trim']);
Assert::same(1, $php['callableReferences']['Nette\Utils\Arrays::first']);
Assert::same(1, $php['staticCalls']['Nette\Utils\Strings::trim']['count']); // not 2

// Instantiation — named, spread
Assert::same(1, $php['instantiations']['Nette\Forms\Form']['count']);
Assert::same(1, $php['instantiations']['Nette\Caching\Storages\FileStorage']['args']['named']['dir']);
Assert::true(isset($php['instantiations']['Nette\Caching\Storages\FileStorage']['args']['spread']));

// Constant — tracked; ::class is not
Assert::true(isset($php['constants']['Nette\Http\IResponse::S200_OK']));
Assert::false(isset($php['constants']['Nette\Http\IResponse::class']));

// Constant via variable — resolves declaring Nette class
Assert::same(3, $php['constants']['Nette\Forms\Form::Equal']); // $form::Equal + $custom::Equal + CustomForm::Equal
Assert::same(1, $php['constants']['Nette\Forms\Container::Array']); // $form::Array → declaring class is Container

// Constant via variable — user-defined on subclass, NOT reported
Assert::false(isset($php['constants']['CustomForm::MY_CUSTOM']));

// ::class via variable — not a constant
Assert::false(isset($php['constants']['Nette\Forms\Form::class']));

// Inheritance
Assert::true(isset($php['inheritance']['extends']['Nette\Forms\Form']));
Assert::true(isset($php['inheritance']['implements']['Nette\Security\Authenticator']));
Assert::true(isset($php['inheritance']['traits']['Nette\SmartObject']));

// Method calls — declaring class resolution
Assert::same(2, $php['methodCalls']['Nette\Forms\Container::addText']['count']); // $form->addText + $custom->addText
Assert::same(1, $php['methodCalls']['Nette\Forms\Container::getValues']['count']); // $form->getValues

// Native property access
Assert::same(1, $php['propertyAccess']['Nette\Forms\Form::$onSuccess']['arrayPush']);
Assert::false(isset($php['propertyAccess']['Nette\Forms\Form::$action'])); // action is virtual, not here

// Virtual property access (@property annotation)
Assert::same(1, $php['virtualPropertyAccess']['Nette\Forms\Form::$action']['read']);
Assert::same(1, $php['virtualPropertyAccess']['Nette\Forms\Form::$action']['write']);
Assert::same(1, $php['virtualPropertyAccess']['Nette\Forms\Form::$method']['read']);
Assert::false(isset($php['virtualPropertyAccess']['Nette\Forms\Form::$onSuccess'])); // onSuccess is native, not here

// User-defined members on subclass — NOT reported (declaring class is not Nette)
Assert::false(isset($php['methodCalls']['CustomForm::customMethod']));
Assert::false(isset($php['propertyAccess']['CustomForm::$customNative']));
Assert::false(isset($php['virtualPropertyAccess']['CustomForm::$customVirtual']));

// Private native + @property-read = virtual access (Control::$template is private, accessed via SmartObject)
Assert::same(1, $php['virtualPropertyAccess']['Nette\Application\UI\Control::$template']['read']);
Assert::false(isset($php['propertyAccess']['Nette\Application\UI\Control::$template']));

// Function calls
Assert::same(1, $php['functionCalls']['test']['count']);
Assert::true(isset($php['functionCalls']['dumpe']));

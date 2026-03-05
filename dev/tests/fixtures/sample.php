<?php declare(strict_types=1);

use Nette\Utils\Arrays;
use Nette\Utils\Strings;

// Static call — return used
$result = Strings::trim('  hello  ');

// Static call — return discarded
Strings::contains('hello', 'ell');

// Named arguments
Strings::padLeft('hi', length: 10, pad: '.');

// Spread arguments
$args = ['hello', '#pattern#'];
Strings::match(...$args);

// First-class callable
$trimmer = Strings::trim(...);
Arrays::first(...);

// Instance method call
$form = new Nette\Forms\Form;
$form->addText('name', 'Name:');

// Instance first-class callable
$adder = $form->addText(...);

// Instantiation — named args
$fileStorage = new Nette\Caching\Storages\FileStorage(dir: '/tmp/cache');

// Instantiation — spread
$ctorArgs = ['/tmp/cache'];
$storage2 = new Nette\Caching\Storages\FileStorage(...$ctorArgs);

// Anonymous class (should be skipped)
$anon = new class extends Nette\Forms\Form {
};

// Class constant
$ok = Nette\Http\IResponse::S200_OK;

// ::class is NOT a constant
$className = Nette\Http\IResponse::class;

// Static property
$defaults = Nette\Forms\Form::$defaultRenderer;

// Property access — read, write, arrayPush, reference
$action = $form->action;
$form->action = '/submit';
$form->onSuccess[] = function () {};
$ref = &$form->onSuccess;

// Virtual property access (via @property annotation, not native)
$method = $form->method;

// Extends
/**
 * @property string $customVirtual
 */
class CustomForm extends Nette\Forms\Form
{
	public const MY_CUSTOM = 'custom';
	public int $customNative = 0;


	public function customMethod(): void
	{
	}


	public function getCustomVirtual(): string
	{
		return '';
	}
}

// Implements
class MyAuthenticator implements Nette\Security\Authenticator
{
	public function authenticate(string $user, string $password): Nette\Security\IIdentity
	{
		throw new Exception('not implemented');
	}
}

// Traits
class ModelBase
{
	use Nette\SmartObject;
}

// Method override
class MyPresenter extends Nette\Application\UI\Presenter
{
	public function startup(): void
	{
		parent::startup();
	}
}

// Global functions — Tester
test('basic test', function () {
});

// Global functions — Tracy
dumpe($result);
bdump($result, 'label');

// Dynamic static call
$class = Strings::class;
$class::trim('  foo  ');

// Constant via variable — declared on Nette class
$form::Equal;

// Constant via variable — inherited from Nette parent (Container::Array)
$form::Array;

// Constant via variable on subclass — inherited constant resolves to declaring Nette class
$custom = new CustomForm;
$custom::Equal;

// Constant via variable on subclass — user-defined constant, NOT on Nette class
$custom::MY_CUSTOM;

// ::class via variable — still not a constant
$form::class;

// Constant via class name on subclass — declaring class resolution
CustomForm::Equal;

// Method call via variable on subclass — declaring class resolution (addText declared on Container)
$custom->addText('field', 'Label:');

// Method call — declared on Nette parent (getValues declared on Container)
$form->getValues();

// User-defined members on subclass — should NOT be reported
$custom->customMethod();
$custom->customNative;
$custom->customVirtual;

// Private native property with @property-read — virtual access (Control::$template is private + @property-read)
$presenter = new MyPresenter;
$tpl = $presenter->template;

<?php declare(strict_types=1);

class SomeClass
{
    public function someFunction()
    {
        yield \Namespace1\Namespace2\Namespace3\Namespace4\Namespace5\Namespace6\MyClass::someLongStaticMethod(
            self::getArgument($parameter),
            self::getArgument($parameter)
        );
    }
}

<?php declare(strict_types = 1);

namespace PHPStan\Type\WebMozartAssert;

use ArrayAccess;
use Closure;
use Countable;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Greater;
use PhpParser\Node\Expr\BinaryOp\GreaterOrEqual;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BinaryOp\SmallerOrEqual;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\SpecifiedTypes;
use PHPStan\Analyser\TypeSpecifier;
use PHPStan\Analyser\TypeSpecifierAwareExtension;
use PHPStan\Analyser\TypeSpecifierContext;
use PHPStan\Reflection\MethodReflection;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IterableType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StaticMethodTypeSpecifyingExtension;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;
use ReflectionObject;
use Traversable;
use function array_key_exists;
use function count;
use function key;
use function lcfirst;
use function reset;
use function substr;

class AssertTypeSpecifyingExtension implements StaticMethodTypeSpecifyingExtension, TypeSpecifierAwareExtension
{

	/** @var Closure[] */
	private static $resolvers;

	/** @var TypeSpecifier */
	private $typeSpecifier;

	public function setTypeSpecifier(TypeSpecifier $typeSpecifier): void
	{
		$this->typeSpecifier = $typeSpecifier;
	}

	public function getClass(): string
	{
		return 'Webmozart\Assert\Assert';
	}

	public function isStaticMethodSupported(
		MethodReflection $staticMethodReflection,
		StaticCall $node,
		TypeSpecifierContext $context
	): bool
	{
		if (substr($staticMethodReflection->getName(), 0, 6) === 'allNot') {
			$methods = [
				'allNotInstanceOf' => 2,
				'allNotNull' => 1,
				'allNotSame' => 2,
			];
			return array_key_exists($staticMethodReflection->getName(), $methods)
				&& count($node->getArgs()) >= $methods[$staticMethodReflection->getName()];
		}

		$trimmedName = self::trimName($staticMethodReflection->getName());
		$resolvers = self::getExpressionResolvers();

		if (!array_key_exists($trimmedName, $resolvers)) {
			return false;
		}

		$resolver = $resolvers[$trimmedName];
		$resolverReflection = new ReflectionObject($resolver);

		return count($node->getArgs()) >= count($resolverReflection->getMethod('__invoke')->getParameters()) - 1;
	}

	private static function trimName(string $name): string
	{
		if (substr($name, 0, 6) === 'nullOr') {
			$name = substr($name, 6);
		}
		if (substr($name, 0, 3) === 'all') {
			$name = substr($name, 3);
		}

		return lcfirst($name);
	}

	public function specifyTypes(
		MethodReflection $staticMethodReflection,
		StaticCall $node,
		Scope $scope,
		TypeSpecifierContext $context
	): SpecifiedTypes
	{
		if (substr($staticMethodReflection->getName(), 0, 6) === 'allNot') {
			return $this->handleAllNot(
				$staticMethodReflection->getName(),
				$node,
				$scope
			);
		}
		$expression = self::createExpression($scope, $staticMethodReflection->getName(), $node->getArgs());
		if ($expression === null) {
			return new SpecifiedTypes([], []);
		}
		$specifiedTypes = $this->typeSpecifier->specifyTypesInCondition(
			$scope,
			$expression,
			TypeSpecifierContext::createTruthy()
		);

		if (substr($staticMethodReflection->getName(), 0, 3) === 'all') {
			if (count($specifiedTypes->getSureTypes()) > 0) {
				$sureTypes = $specifiedTypes->getSureTypes();
				reset($sureTypes);
				$exprString = key($sureTypes);
				$sureType = $sureTypes[$exprString];
				return $this->arrayOrIterable(
					$scope,
					$sureType[0],
					static function () use ($sureType): Type {
						return $sureType[1];
					}
				);
			}
			if (count($specifiedTypes->getSureNotTypes()) > 0) {
				throw new ShouldNotHappenException();
			}
		}

		return $specifiedTypes;
	}

	/**
	 * @param Arg[] $args
	 */
	private static function createExpression(
		Scope $scope,
		string $name,
		array $args
	): ?Expr
	{
		$trimmedName = self::trimName($name);
		$resolvers = self::getExpressionResolvers();
		$resolver = $resolvers[$trimmedName];
		$expression = $resolver($scope, ...$args);
		if ($expression === null) {
			return null;
		}

		if (substr($name, 0, 6) === 'nullOr') {
			$expression = new BooleanOr(
				$expression,
				new Identical(
					$args[0]->value,
					new ConstFetch(new Name('null'))
				)
			);
		}

		return $expression;
	}

	/**
	 * @return Closure[]
	 */
	private static function getExpressionResolvers(): array
	{
		if (self::$resolvers === null) {
			self::$resolvers = [
				'integer' => static function (Scope $scope, Arg $value): Expr {
					return new FuncCall(
						new Name('is_int'),
						[$value]
					);
				},
				'positiveInteger' => static function (Scope $scope, Arg $value): Expr {
					return new BooleanAnd(
						new FuncCall(
							new Name('is_int'),
							[$value]
						),
						new Greater(
							$value->value,
							new LNumber(0)
						)
					);
				},
				'string' => static function (Scope $scope, Arg $value): Expr {
					return new FuncCall(
						new Name('is_string'),
						[$value]
					);
				},
				'stringNotEmpty' => static function (Scope $scope, Arg $value): Expr {
					return new BooleanAnd(
						new FuncCall(
							new Name('is_string'),
							[$value]
						),
						new NotIdentical(
							$value->value,
							new String_('')
						)
					);
				},
				'float' => static function (Scope $scope, Arg $value): Expr {
					return new FuncCall(
						new Name('is_float'),
						[$value]
					);
				},
				'integerish' => static function (Scope $scope, Arg $value): Expr {
					return new FuncCall(
						new Name('is_numeric'),
						[$value]
					);
				},
				'numeric' => static function (Scope $scope, Arg $value): Expr {
					return new FuncCall(
						new Name('is_numeric'),
						[$value]
					);
				},
				'natural' => static function (Scope $scope, Arg $value): Expr {
					return new BooleanAnd(
						new FuncCall(
							new Name('is_int'),
							[$value]
						),
						new GreaterOrEqual(
							$value->value,
							new LNumber(0)
						)
					);
				},
				'boolean' => static function (Scope $scope, Arg $value): Expr {
					return new FuncCall(
						new Name('is_bool'),
						[$value]
					);
				},
				'scalar' => static function (Scope $scope, Arg $value): Expr {
					return new FuncCall(
						new Name('is_scalar'),
						[$value]
					);
				},
				'object' => static function (Scope $scope, Arg $value): Expr {
					return new FuncCall(
						new Name('is_object'),
						[$value]
					);
				},
				'resource' => static function (Scope $scope, Arg $value): Expr {
					return new FuncCall(
						new Name('is_resource'),
						[$value]
					);
				},
				'isCallable' => static function (Scope $scope, Arg $value): Expr {
					return new FuncCall(
						new Name('is_callable'),
						[$value]
					);
				},
				'isArray' => static function (Scope $scope, Arg $value): Expr {
					return new FuncCall(
						new Name('is_array'),
						[$value]
					);
				},
				'isIterable' => static function (Scope $scope, Arg $expr): Expr {
					return new BooleanOr(
						new FuncCall(
							new Name('is_array'),
							[$expr]
						),
						new Instanceof_(
							$expr->value,
							new Name(Traversable::class)
						)
					);
				},
				'isList' => static function (Scope $scope, Arg $expr): Expr {
					return new BooleanAnd(
						new FuncCall(
							new Name('is_array'),
							[$expr]
						),
						new Identical(
							$expr->value,
							new FuncCall(
								new Name('array_values'),
								[$expr]
							)
						)
					);
				},
				'isCountable' => static function (Scope $scope, Arg $expr): Expr {
					return new BooleanOr(
						new FuncCall(
							new Name('is_array'),
							[$expr]
						),
						new Instanceof_(
							$expr->value,
							new Name(Countable::class)
						)
					);
				},
				'isInstanceOf' => static function (Scope $scope, Arg $expr, Arg $class): ?Expr {
					$classType = $scope->getType($class->value);
					if (!$classType instanceof ConstantStringType) {
						return null;
					}

					return new Instanceof_(
						$expr->value,
						new Name($classType->getValue())
					);
				},
				'notInstanceOf' => static function (Scope $scope, Arg $expr, Arg $class): ?Expr {
					$classType = $scope->getType($class->value);
					if (!$classType instanceof ConstantStringType) {
						return null;
					}

					return new BooleanNot(
						new Instanceof_(
							$expr->value,
							new Name($classType->getValue())
						)
					);
				},
				'implementsInterface' => static function (Scope $scope, Arg $expr, Arg $class): ?Expr {
					$classType = $scope->getType($class->value);
					if (!$classType instanceof ConstantStringType) {
						return null;
					}

					return new Instanceof_(
						$expr->value,
						new Name($classType->getValue())
					);
				},
				'keyExists' => static function (Scope $scope, Arg $array, Arg $key): Expr {
					return new FuncCall(
						new Name('array_key_exists'),
						[$key, $array]
					);
				},
				'keyNotExists' => static function (Scope $scope, Arg $array, Arg $key): Expr {
					return new BooleanNot(
						new FuncCall(
							new Name('array_key_exists'),
							[$key, $array]
						)
					);
				},
				'validArrayKey' => static function (Scope $scope, Arg $value): Expr {
					return new BooleanOr(
						new FuncCall(
							new Name('is_int'),
							[$value]
						),
						new FuncCall(
							new Name('is_string'),
							[$value]
						)
					);
				},
				'true' => static function (Scope $scope, Arg $expr): Expr {
					return new Identical(
						$expr->value,
						new ConstFetch(new Name('true'))
					);
				},
				'false' => static function (Scope $scope, Arg $expr): Expr {
					return new Identical(
						$expr->value,
						new ConstFetch(new Name('false'))
					);
				},
				'null' => static function (Scope $scope, Arg $expr): Expr {
					return new Identical(
						$expr->value,
						new ConstFetch(new Name('null'))
					);
				},
				'notFalse' => static function (Scope $scope, Arg $expr): Expr {
					return new NotIdentical(
						$expr->value,
						new ConstFetch(new Name('false'))
					);
				},
				'notNull' => static function (Scope $scope, Arg $expr): Expr {
					return new NotIdentical(
						$expr->value,
						new ConstFetch(new Name('null'))
					);
				},
				'same' => static function (Scope $scope, Arg $value1, Arg $value2): Expr {
					return new Identical(
						$value1->value,
						$value2->value
					);
				},
				'notSame' => static function (Scope $scope, Arg $value1, Arg $value2): Expr {
					return new NotIdentical(
						$value1->value,
						$value2->value
					);
				},
				'subclassOf' => static function (Scope $scope, Arg $expr, Arg $class): Expr {
					return new FuncCall(
						new Name('is_subclass_of'),
						[
							new Arg($expr->value),
							$class,
						]
					);
				},
				'classExists' => static function (Scope $scope, Arg $class): Expr {
					return new FuncCall(
						new Name('class_exists'),
						[$class]
					);
				},
				'interfaceExists' => static function (Scope $scope, Arg $class): Expr {
					return new FuncCall(
						new Name('interface_exists'),
						[$class]
					);
				},
				'count' => static function (Scope $scope, Arg $array, Arg $number): Expr {
					return new Identical(
						new FuncCall(
							new Name('count'),
							[$array]
						),
						$number->value
					);
				},
				'minCount' => static function (Scope $scope, Arg $array, Arg $min): Expr {
					return new GreaterOrEqual(
						new FuncCall(
							new Name('count'),
							[$array]
						),
						$min->value
					);
				},
				'maxCount' => static function (Scope $scope, Arg $array, Arg $max): Expr {
					return new SmallerOrEqual(
						new FuncCall(
							new Name('count'),
							[$array]
						),
						$max->value
					);
				},
				'countBetween' => static function (Scope $scope, Arg $array, Arg $min, Arg $max): Expr {
					return new BooleanAnd(
						new GreaterOrEqual(
							new FuncCall(
								new Name('count'),
								[$array]
							),
							$min->value
						),
						new SmallerOrEqual(
							new FuncCall(
								new Name('count'),
								[$array]
							),
							$max->value
						)
					);
				},
				'length' => static function (Scope $scope, Arg $value, Arg $length): Expr {
					return new BooleanAnd(
						new FuncCall(
							new Name('is_string'),
							[$value]
						),
						new Identical(
							new FuncCall(
								new Name('strlen'),
								[$value]
							),
							$length->value
						)
					);
				},
				'minLength' => static function (Scope $scope, Arg $value, Arg $min): Expr {
					return new BooleanAnd(
						new FuncCall(
							new Name('is_string'),
							[$value]
						),
						new GreaterOrEqual(
							new FuncCall(
								new Name('strlen'),
								[$value]
							),
							$min->value
						)
					);
				},
				'maxLength' => static function (Scope $scope, Arg $value, Arg $max): Expr {
					return new BooleanAnd(
						new FuncCall(
							new Name('is_string'),
							[$value]
						),
						new SmallerOrEqual(
							new FuncCall(
								new Name('strlen'),
								[$value]
							),
							$max->value
						)
					);
				},
				'lengthBetween' => static function (Scope $scope, Arg $value, Arg $min, Arg $max): Expr {
					return new BooleanAnd(
						new FuncCall(
							new Name('is_string'),
							[$value]
						),
						new BooleanAnd(
							new GreaterOrEqual(
								new FuncCall(
									new Name('strlen'),
									[$value]
								),
								$min->value
							),
							new SmallerOrEqual(
								new FuncCall(
									new Name('strlen'),
									[$value]
								),
								$max->value
							)
						)
					);
				},
				'inArray' => static function (Scope $scope, Arg $needle, Arg $array): Expr {
					return new FuncCall(
						new Name('in_array'),
						[
							$needle,
							$array,
							new Arg(new ConstFetch(new Name('true'))),
						]
					);
				},
				'oneOf' => static function (Scope $scope, Arg $needle, Arg $array): Expr {
					return new FuncCall(
						new Name('in_array'),
						[
							$needle,
							$array,
							new Arg(new ConstFetch(new Name('true'))),
						]
					);
				},
				'methodExists' => static function (Scope $scope, Arg $object, Arg $method): Expr {
					return new FuncCall(
						new Name('method_exists'),
						[$object, $method]
					);
				},
				'propertyExists' => static function (Scope $scope, Arg $object, Arg $property): Expr {
					return new FuncCall(
						new Name('property_exists'),
						[$object, $property]
					);
				},
				'isArrayAccessible' => static function (Scope $scope, Arg $expr): Expr {
					return new BooleanOr(
						new FuncCall(
							new Name('is_array'),
							[$expr]
						),
						new Instanceof_(
							$expr->value,
							new Name(ArrayAccess::class)
						)
					);
				},
			];
		}

		return self::$resolvers;
	}

	private function handleAllNot(
		string $methodName,
		StaticCall $node,
		Scope $scope
	): SpecifiedTypes
	{
		if ($methodName === 'allNotNull') {
			return $this->arrayOrIterable(
				$scope,
				$node->getArgs()[0]->value,
				static function (Type $type): Type {
					return TypeCombinator::removeNull($type);
				}
			);
		}

		if ($methodName === 'allNotInstanceOf') {
			$classType = $scope->getType($node->getArgs()[1]->value);
			if (!$classType instanceof ConstantStringType) {
				return new SpecifiedTypes([], []);
			}

			$objectType = new ObjectType($classType->getValue());
			return $this->arrayOrIterable(
				$scope,
				$node->getArgs()[0]->value,
				static function (Type $type) use ($objectType): Type {
					return TypeCombinator::remove($type, $objectType);
				}
			);
		}

		if ($methodName === 'allNotSame') {
			$valueType = $scope->getType($node->getArgs()[1]->value);
			return $this->arrayOrIterable(
				$scope,
				$node->getArgs()[0]->value,
				static function (Type $type) use ($valueType): Type {
					return TypeCombinator::remove($type, $valueType);
				}
			);
		}

		throw new ShouldNotHappenException();
	}

	private function arrayOrIterable(
		Scope $scope,
		Expr $expr,
		Closure $typeCallback
	): SpecifiedTypes
	{
		$currentType = TypeCombinator::intersect($scope->getType($expr), new IterableType(new MixedType(), new MixedType()));
		$arrayTypes = TypeUtils::getArrays($currentType);
		if (count($arrayTypes) > 0) {
			$newArrayTypes = [];
			foreach ($arrayTypes as $arrayType) {
				if ($arrayType instanceof ConstantArrayType) {
					$builder = ConstantArrayTypeBuilder::createEmpty();
					foreach ($arrayType->getKeyTypes() as $i => $keyType) {
						$valueType = $arrayType->getValueTypes()[$i];
						$builder->setOffsetValueType($keyType, $typeCallback($valueType));
					}
					$newArrayTypes[] = $builder->getArray();
				} else {
					$newArrayTypes[] = new ArrayType($arrayType->getKeyType(), $typeCallback($arrayType->getItemType()));
				}
			}

			$specifiedType = TypeCombinator::union(...$newArrayTypes);
		} elseif ((new IterableType(new MixedType(), new MixedType()))->isSuperTypeOf($currentType)->yes()) {
			$specifiedType = new IterableType($currentType->getIterableKeyType(), $typeCallback($currentType->getIterableValueType()));
		} else {
			return new SpecifiedTypes([], []);
		}

		return $this->typeSpecifier->create(
			$expr,
			$specifiedType,
			TypeSpecifierContext::createTruthy()
		);
	}

}

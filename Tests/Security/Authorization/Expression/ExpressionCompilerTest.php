<?php

namespace JMS\SecurityExtraBundle\Tests\Security\Authorization\Expression;

use Symfony\Component\Security\Core\Role\Role;
use JMS\SecurityExtraBundle\Security\Authorization\Expression\Expression;
use JMS\SecurityExtraBundle\Security\Authorization\Expression\ExpressionCompiler;

class ExpressionCompilerTest extends \PHPUnit_Framework_TestCase
{
    private $compiler;

    public function testCompileExpression()
    {
        $evaluator = eval($this->compiler->compileExpression(new Expression('isAnonymous()')));

        $token = $this->getMock('Symfony\Component\Security\Core\Authentication\Token\TokenInterface');

        $trustResolver = $this->getMock('Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface');
        $trustResolver->expects($this->once())
            ->method('isAnonymous')
            ->with($token)
            ->will($this->returnValue(true));

        $context = array(
            'token' => $token,
            'trust_resolver' => $trustResolver,
        );

        $this->assertTrue($evaluator($context));
    }

    public function testCompileComplexExpression()
    {
        $evaluator = eval($this->compiler->compileExpression(
            new Expression('hasRole("ADMIN") or hasAnyRole("FOO", "BAR")')));

        $token = $this->getMock('Symfony\Component\Security\Core\Authentication\Token\TokenInterface');
        $token->expects($this->once())
            ->method('getRoles')
            ->will($this->returnValue(array(new Role('FOO'))));
        $this->assertTrue($evaluator(array('token' => $token)));

        $token = $this->getMock('Symfony\Component\Security\Core\Authentication\Token\TokenInterface');
        $token->expects($this->once())
            ->method('getRoles')
            ->will($this->returnValue(array(new Role('BAZ'))));
        $this->assertFalse($evaluator(array('token' => $token)));
    }

    protected function setUp()
    {
        $this->compiler = new ExpressionCompiler();
    }
}
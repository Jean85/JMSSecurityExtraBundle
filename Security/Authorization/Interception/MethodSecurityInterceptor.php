<?php

/*
 * Copyright 2011 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\SecurityExtraBundle\Security\Authorization\Interception;

use CG\Proxy\MethodInterceptorInterface;
use CG\Proxy\MethodInvocation;
use JMS\SecurityExtraBundle\Exception\RuntimeException;
use JMS\SecurityExtraBundle\Security\Authentication\Token\RunAsUserToken;
use JMS\SecurityExtraBundle\Security\Authorization\AfterInvocation\AfterInvocationManagerInterface;
use JMS\SecurityExtraBundle\Security\Authorization\RunAsManagerInterface;
use Metadata\MetadataFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\AuthenticationManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;

/**
 * All invocations of secure methods will go through this class.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class MethodSecurityInterceptor implements MethodInterceptorInterface
{
    /** @var bool */
    private $alwaysAuthenticate;

    /** @var TokenStorageInterface */
    private $tokenStorage;

    /** @var MetadataFactoryInterface */
    private $metadataFactory;

    /** @var AuthenticationManagerInterface */
    private $authenticationManager;

    /** @var AccessDecisionManagerInterface */
    private $accessDecisionManager;

    /** @var AfterInvocationManagerInterface */
    private $afterInvocationManager;

    /** @var RunAsManagerInterface */
    private $runAsManager;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        TokenStorageInterface $tokenStorage,
        AuthenticationManagerInterface $authenticationManager,
        AccessDecisionManagerInterface $accessDecisionManager,
        AfterInvocationManagerInterface $afterInvocationManager,
        RunAsManagerInterface $runAsManager,
        MetadataFactoryInterface $metadataFactory,
        LoggerInterface $logger = null
    ) {
        $this->alwaysAuthenticate = false;
        $this->tokenStorage = $tokenStorage;
        $this->metadataFactory = $metadataFactory;
        $this->authenticationManager = $authenticationManager;
        $this->accessDecisionManager = $accessDecisionManager;
        $this->afterInvocationManager = $afterInvocationManager;
        $this->runAsManager = $runAsManager;
        $this->logger = $logger;
    }

    /**
     * @param $boolean
     */
    public function setAlwaysAuthenticate($boolean)
    {
        $this->alwaysAuthenticate = (bool) $boolean;
    }

    /**
     * @param MethodInvocation $method
     * @return mixed
     * @throws \Exception
     * @throws RuntimeException
     * @throws AuthenticationCredentialsNotFoundException
     * @throws AccessDeniedException
     */
    public function intercept(MethodInvocation $method)
    {
        $metadata = $this->metadataFactory->getMetadataForClass($method->reflection->class);

        // no security metadata, proceed
        if (empty($metadata) || !isset($metadata->methodMetadata[$method->reflection->name])) {
            return $method->proceed();
        }
        $metadata = $metadata->methodMetadata[$method->reflection->name];

        if (null === $token = $this->tokenStorage->getToken()) {
            throw new AuthenticationCredentialsNotFoundException(
                'The TokenStorage was not populated with a Token.'
            );
        }

        if ($this->alwaysAuthenticate || !$token->isAuthenticated()) {
            $token = $this->authenticationManager->authenticate($token);
            $this->tokenStorage->setToken($token);
        }

        if (!empty($metadata->roles) && false === $this->accessDecisionManager->decide($token, $metadata->roles, $method)) {
            throw new AccessDeniedException('Token does not have the required roles.');
        }

        if (!empty($metadata->paramPermissions)) {
            foreach ($method->arguments as $index => $argument) {
                if (null !== $argument && isset($metadata->paramPermissions[$index]) && false === $this->accessDecisionManager->decide($token, $metadata->paramPermissions[$index], $argument)) {
                    throw new AccessDeniedException(sprintf('Token does not have the required permissions for method "%s::%s".', $method->reflection->class, $method->reflection->name));
                }
            }
        }

        $runAsToken = null;
        if (!empty($metadata->runAsRoles)) {
            $runAsToken = $this->runAsManager->buildRunAs($token, $method, $metadata->runAsRoles);

            if (null !== $this->logger) {
                $this->logger->debug('Populating TokenStorage with RunAsToken');
            }

            if (null === $runAsToken) {
                throw new RuntimeException('RunAsManager must not return null from buildRunAs().');
            }

            $this->tokenStorage->setToken($runAsToken);
        }

        try {
            $returnValue = $method->proceed();

            if (null !== $runAsToken) {
                $this->restoreOriginalToken($runAsToken);
            }

            if (empty($metadata->returnPermissions)) {
                return $returnValue;
            }

            return $this->afterInvocationManager->decide($this->tokenStorage->getToken(), $method, $metadata->returnPermissions, $returnValue);
        } catch (\Exception $failed) {
            if (null !== $runAsToken) {
                $this->restoreOriginalToken($runAsToken);
            }

            throw $failed;
        }
    }

    /**
     * Restores the original user token.
     *
     * @param RunAsUserToken $runAsToken
     */
    private function restoreOriginalToken(RunAsUserToken $runAsToken)
    {
        if (null !== $this->logger) {
            $this->logger->debug('Populating TokenStorage with original Token.');
        }

        $this->tokenStorage->setToken($runAsToken->getOriginalToken());
    }
}

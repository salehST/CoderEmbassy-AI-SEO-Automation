<?php

namespace CoderEmbassy\AiSeoAutomation\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Simple service locator for dependency injection.
 */
class Container {

    /**
     * @var array<string, callable>
     */
    private array $bindings = [];

    /**
     * @var array<string, bool> Singletons that should only be resolved once.
     */
    private array $singletons = [];

    /**
     * @var array<string, mixed> Resolved singleton instances.
     */
    private array $instances = [];

    /**
     * Bind an abstract to a factory.
     *
     * @param string   $abstract Class or interface name.
     * @param callable $factory  Factory function that returns the implementation.
     */
    public function bind( string $abstract, callable $factory ): void {
        $this->bindings[ $abstract ] = $factory;
        unset( $this->singletons[ $abstract ], $this->instances[ $abstract ] );
    }

    /**
     * Bind a singleton.
     *
     * @param string   $abstract Class or interface name.
     * @param callable $factory  Factory function that returns the implementation.
     */
    public function singleton( string $abstract, callable $factory ): void {
        $this->bindings[ $abstract ]   = $factory;
        $this->singletons[ $abstract ]  = true;
        unset( $this->instances[ $abstract ] );
    }

    /**
     * Resolve an abstract from the container.
     *
     * @param string $abstract Class or interface name.
     * @return mixed
     */
    public function make( string $abstract ) {
        if ( isset( $this->singletons[ $abstract ] ) && isset( $this->instances[ $abstract ] ) ) {
            return $this->instances[ $abstract ];
        }

        if ( ! isset( $this->bindings[ $abstract ] ) ) {
            throw new \InvalidArgumentException(
                sprintf(
                    /* translators: %s: class or interface name */
                    esc_html__( 'No binding registered for: %s', 'coderembassy-ai-seo-automation' ),
                    esc_html( $abstract )
                )
            );
        }

        $instance = ( $this->bindings[ $abstract ] )( $this );

        if ( isset( $this->singletons[ $abstract ] ) ) {
            $this->instances[ $abstract ] = $instance;
        }

        return $instance;
    }
}

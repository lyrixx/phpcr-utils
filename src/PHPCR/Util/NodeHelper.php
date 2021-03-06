<?php

namespace PHPCR\Util;

use PHPCR\SessionInterface;
use PHPCR\ItemInterface;
use PHPCR\RepositoryException;
use PHPCR\NamespaceException;

/**
 * Helper with only static methods to work with PHPCR nodes
 *
 * @author Daniel Barsotti <daniel.barsotti@liip.ch>
 * @author David Buchmann <david@liip.ch>
 */
class NodeHelper
{
    /**
     * Do not create an instance of this class
     */
    private function __construct()
    {
    }

    /**
     * Create a node and it's parents, if necessary.  Like mkdir -p.
     *
     * @param SessionInterface $session the phpcr session to create the path
     * @param string           $path    full path, like /content/jobs/data
     *
     * @return \PHPCR\NodeInterface the last node of the path, i.e. data
     */
    public static function createPath(SessionInterface $session, $path)
    {
        $current = $session->getRootNode();

        $segments = preg_split('#/#', $path, null, PREG_SPLIT_NO_EMPTY);
        foreach ($segments as $segment) {
            if ($current->hasNode($segment)) {
                $current = $current->getNode($segment);
            } else {
                $current = $current->addNode($segment);
            }
        }

        return $current;
    }

    /**
     * Delete all the nodes in the repository which are not in a system namespace
     *
     * Note that if you want to delete a node under your root node, you can just
     * use the remove method on that node. This method is just here to help you
     * because the implementation might add nodes like jcr:system to the root
     * node which you are not allowed to remove.
     *
     * @param SessionInterface $session the session to remove all children of
     *      the root node
     *
     * @see isSystemItem
     */
    public static function deleteAllNodes(SessionInterface $session)
    {
        $root = $session->getRootNode();
        foreach ($root->getNodes() as $node) {
            if (! self::isSystemItem($node)) {
                $node->remove();
            }
        }
        foreach ($root->getProperties() as $property) {
            if (! self::isSystemItem($property)) {
                $property->remove();
            }
        }
    }

    /**
     * Determine whether this item has a namespace that is to be considered
     * a system namespace
     */
    public static function isSystemItem(ItemInterface $item)
    {
        $name = $item->getName();

        return strpos($name, 'jcr:') === 0 || strpos($name, 'rep:') === 0;
    }

    /**
     * Helper method to implement NodeInterface::addNodeAutoNamed
     *
     * This method only checks for valid namespaces. All other exceptions must
     * be thrown by the addNodeAutoNamed implementation.
     *
     * @param string[] $usedNames  list of child names that is currently used and may not be chosen.
     * @param string[] $namespaces namespace prefix to uri map of all currently known namespaces.
     * @param string $defaultNamespace namespace prefix to use if the hint does not specify.
     * @param string $nameHint the name hint according to the API definition
     *
     * @return string A valid node name for this node
     *
     * @throws NamespaceException if a namespace prefix is provided in the
     *      $nameHint which does not exist and this implementation performs
     *      this validation immediately.
     */
    public static function generateAutoNodeName($usedNames, $namespaces, $defaultNamespace, $nameHint = null)
    {
        $usedNames = array_flip($usedNames);

        /*
         * null: The new node name will be generated entirely by the repository.
         */
        if (null === $nameHint) {

            return self::generateWithPrefix($usedNames, $defaultNamespace . ':');
        }

        /*
         * "" (the empty string), ":" (colon) or "{}": The new node name will
         * be in the empty namespace and the local part of the name will be
         * generated by the repository.
         */
        if ('' === $nameHint || ':' == $nameHint || '{}' == $nameHint) {

            return self::generateWithPrefix($usedNames, '');
        }

         /*
          * "<i>somePrefix</i>:" where <i>somePrefix</i> is a syntactically
          * valid namespace prefix
          */
        if (':' == $nameHint[strlen($nameHint)-1]
            && substr_count($nameHint, ':') === 1
            && preg_match('#^[a-zA-Z][a-zA-Z0-9]*:$#', $nameHint)
        ) {
            $prefix = substr($nameHint, 0, -1);
            if (! isset($namespaces[$prefix])) {
                throw new NamespaceException("Invalid nameHint '$nameHint'");
            }

            return self::generateWithPrefix($usedNames, $prefix . ':');
        }

        /*
         * "{<i>someURI</i>}" where <i>someURI</i> is a syntactically valid
         * namespace URI
         */
        if (strlen($nameHint) > 2
            && '{' == $nameHint[0]
            && '}' == $nameHint[strlen($nameHint)-1]
            && filter_var(substr($nameHint, 1, -1), FILTER_VALIDATE_URL)
        ) {
            $prefix = array_search(substr($nameHint, 1, -1), $namespaces);
            if (! $prefix) {
                throw new NamespaceException("Invalid nameHint '$nameHint'");
            }

            return self::generateWithPrefix($usedNames, $prefix . ':');
        }

        /*
         * "<i>somePrefix</i>:<i>localNameHint</i>" where <i>somePrefix</i> is
         * a syntactically valid namespace prefix and <i>localNameHint</i> is
         * syntactically valid local name: The repository will attempt to create a
         * name in the namespace represented by that prefix as described in (3),
         * above. The local part of the name is generated by the repository using
         * <i>localNameHint</i> as a basis. The way in which the local name is
         * constructed from the hint may vary across implementations.
         */
        if (1 === substr_count($nameHint, ':')) {
            list($prefix, $name) = explode(':', $nameHint);
            if (preg_match('#^[a-zA-Z][a-zA-Z0-9]*$#', $prefix)
                && preg_match('#^[a-zA-Z][a-zA-Z0-9]*$#', $name)
            ) {
                if (! isset($namespaces[$prefix])) {
                    throw new NamespaceException("Invalid nameHint '$nameHint'");
                }

                return self::generateWithPrefix($usedNames, $prefix . ':', $name);
            }
        }

        /*
         * "{<i>someURI</i>}<i>localNameHint</i>" where <i>someURI</i> is a
         * syntactically valid namespace URI and <i>localNameHint</i> is
         * syntactically valid local name: The repository will attempt to create a
         * name in the namespace specified as described in (4), above. The local
         * part of the name is generated by the repository using <i>localNameHint</i>
         * as a basis. The way in which the local name is constructed from the hint
         * may vary across implementations.
         */
        $matches = array();
        //if (preg_match('#^\\{([^\\}]+)\\}([a-zA-Z][a-zA-Z0-9]*)$}#', $nameHint, $matches)) {
        if (preg_match('#^\\{([^\\}]+)\\}([a-zA-Z][a-zA-Z0-9]*)$#', $nameHint, $matches)) {
            $ns = $matches[1];
            $name = $matches[2];

            $prefix = array_search($ns, $namespaces);
            if (! $prefix) {
                throw new NamespaceException("Invalid nameHint '$nameHint'");
            }

            return self::generateWithPrefix($usedNames, $prefix . ':', $name);
        }

        throw new RepositoryException("Invalid nameHint '$nameHint'");
    }

    /**
     * @param string[] $usedNames names that are forbidden
     * @param string $prefix the prefix including the colon at the end
     * @param string $namepart start for the localname
     *
     * @return string
     */
    private static function generateWithPrefix($usedNames, $prefix, $namepart = '')
    {
        do {
            $name = $prefix . $namepart . mt_rand();
        } while (isset($usedNames[$name]));

        return $name;
    }
}

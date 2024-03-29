<?php

/**
 * @package toolkit
 */
/**
 * The `ResourcesPage` abstract class controls the way "Datasource"
 * and "Events" index pages are displayed in the backend. It extends the
 * `AdministrationPage` class.
 *
 * @since Symphony 2.3
 * @see toolkit.AdministrationPage
 */

abstract class ResourcesPage extends AdministrationPage
{
    /**
     * The Resources page has /action/handle/flag/ context.
     * eg. /edit/1/saved/
     *
     * @param array $context
     * @param array $parts
     * @return array
     */
    public function parseContext(array &$context, array $parts)
    {
        // Order is important!
        $params = array_fill_keys(['action', 'handle', 'flag'], null);

        if (isset($parts[2])) {
            $extras = preg_split('/\//', $parts[2], -1, PREG_SPLIT_NO_EMPTY);
            $params['action'] = $extras[0];
            $params['handle'] = $extras[1] ?? null;
            $params['flag'] = $extras[2] ?? null;
        }

        $context = array_filter($params);
    }

    /**
     * This method is invoked from the `Sortable` class and it contains the
     * logic for sorting (or unsorting) the resource index. It provides a basic
     * wrapper to the `ResourceManager`'s `fetch()` method.
     *
     * @see toolkit.ResourceManager#getSortingField
     * @see toolkit.ResourceManager#getSortingOrder
     * @see toolkit.ResourceManager#fetch
     * @param string $sort
     *  The field to sort on which should match one of the table's column names.
     *  If this is not provided the default will be determined by
     *  `ResourceManager::getSortingField`
     * @param string $order
     *  The direction to sort in, either 'asc' or 'desc'. If this is not provided
     *  the value will be determined by `ResourceManager::getSortingOrder`.
     * @param array $params
     *  An associative array of params (usually populated from the URL) that this
     *  function uses. The current implementation will use `type` and `unsort` keys
     * @throws Exception
     * @throws SymphonyException
     * @return array
     *  An associative of the resource as determined by `ResourceManager::fetch`
     */
    public function sort(&$sort, &$order, array $params)
    {
        $type = $params['type'];

        if (!is_null($sort)) {
            General::sanitize($sort);
        }

        // If `?unsort` is appended to the URL, then sorting information are reverted
        // to their defaults
        if (isset($params['unsort'])) {
            ResourceManager::setSortingField($type, 'name', false);
            ResourceManager::setSortingOrder($type, 'asc');

            redirect(Administration::instance()->getCurrentPageURL());
        }

        // By default, sorting information are retrieved from
        // the filesystem and stored inside the `Configuration` object
        if (is_null($sort)) {
            $sort = ResourceManager::getSortingField($type);
            $order = ResourceManager::getSortingOrder($type);

            // If the sorting field or order differs from what is saved,
            // update the config file and reload the page
        } elseif ($sort !== ResourceManager::getSortingField($type) || $order !== ResourceManager::getSortingOrder($type)) {
            ResourceManager::setSortingField($type, $sort, false);
            ResourceManager::setSortingOrder($type, $order);

            redirect(Administration::instance()->getCurrentPageURL());
        }

        return ResourceManager::fetch($params['type'], [], [], $sort . ' ' . $order);
    }

    /**
     * This function creates an array of all page titles in the system.
     *
     * @return array
     *  An array of page titles
     */
    public function pagesFlatView(): array
    {
        $pages = (new PageManager)->select(['id'])->execute()->rows();

        foreach ($pages as &$p) {
            $p['title'] = PageManager::resolvePageTitle($p['id']);
        }

        return $pages;
    }

    /**
     * This function contains the minimal amount of logic for generating the
     * index table of a given `$resource_type`. The table has name, source, pages
     * release date and author columns. The values for these columns are determined
     * by the resource's `about()` method.
     *
     * As Datasources types can be installed using Providers, the Source column
     * can be overridden with a Datasource's `getSourceColumn` method (if it exists).
     *
     * @param integer $resource_type
     *  Either `ResourceManager::RESOURCE_TYPE_EVENT` or `ResourceManager::RESOURCE_TYPE_DATASOURCE`
     * @throws InvalidArgumentException
     */
    public function __viewIndex(int $resource_type)
    {
        $manager = ResourceManager::getManagerFromType($resource_type);
        $friendly_resource = ($resource_type === ResourceManager::RESOURCE_TYPE_EVENT) ? __('Event') : __('DataSource');
        $context = Administration::instance()->getPageCallback();

        $this->setPageType('table');

        $resources = null;
        $sort = null;
        $order = null;
        Sortable::initialize($this, $resources, $sort, $order, [
            'type' => $resource_type,
        ]);

        $columns = [
            [
                'label' => __('Name'),
                'sortable' => true,
                'handle' => 'name'
            ],
            [
                'label' => __('Source'),
                'sortable' => true,
                'handle' => 'source'
            ],
            [
                'label' => __('Pages'),
                'sortable' => false,
            ],
            [
                'label' => __('Author'),
                'sortable' => true,
                'handle' => 'author'
            ]
        ];

        /**
         * Allows the creation of custom table columns for each resource. Called
         * after all the table headers columns have been added.
         *
         * @delegate AddCustomResourceColumn
         * @since Symphony 3.0.0
         * @param string $context
         *  '/blueprints/datasources/' or '/blueprints/events/'
         * @param array $columns
         *  An array of the current columns, passed by reference
         * @param string $sort
         *  The sort field
         * @param string $order
         *  The sort order
         * @param int $resource_type
         *  The resource type, i.e. `ResourceManager::RESOURCE_TYPE_EVENT` or
         *  `ResourceManager::RESOURCE_TYPE_DATASOURCE`.
         * @param array $resources
         *  The resources array
         * @param object $manager
         *  The resources manager
         */
        Symphony::ExtensionManager()->notifyMembers(
            'AddCustomResourceColumn', $context['pageroot'], [
                'columns' => &$columns,
                'sort' => $sort,
                'order' => $order,
                'resource_type' => $resource_type,
                'resources' => $resources,
                'manager' => $manager
            ]
        );

        $aTableHead = Sortable::buildTableHeaders($columns, $sort, $order, isset($_REQUEST['filter']) ? '&amp;filter=' . $_REQUEST['filter'] : '');

        $aTableBody = [];

        if (!is_array($resources) || empty($resources)) {
            $aTableBody = [Widget::TableRow([Widget::TableData(__('None found.'), 'inactive', null, count($aTableHead))], 'odd')];
        } else {
            foreach ($resources as $r) {
                $action = 'edit';
                $status = null;
                $locked = null;

                // Locked resources
                if (isset($r['can_parse']) && $r['can_parse'] !== true) {
                    $action = 'info';
                    $status = 'status-notice';
                    $locked = [
                        'data-status' => ' — ' . __('read only')
                    ];
                }

                $name = Widget::TableData(
                    Widget::Anchor(
                        stripslashes($r['name']),
                        SYMPHONY_URL . $context['pageroot'] .  $action . '/' . $r['handle'] . '/',
                        $r['handle'],
                        'resource-' . $action,
                        null,
                        $locked
                    )
                );

                $name->appendChild(Widget::Label(__('Select ' . $friendly_resource . ' %s', [$r['name']]), null, 'accessible', null, [
                    'for' => 'resource-' . $r['handle']
                ]));
                $name->appendChild(Widget::Input('items['.$r['handle'].']', 'on', 'checkbox', [
                    'id' => 'resource-' . $r['handle']
                ]));

                // Resource type/source
                if (isset($r['source'], $r['source']['id'])) {
                    $section = Widget::TableData(
                        Widget::Anchor(
                            $r['source']['name'],
                            SYMPHONY_URL . '/blueprints/sections/edit/' . $r['source']['id'] . '/',
                            $r['source']['handle']
                        )
                    );
                } elseif (isset($r['source']) && class_exists($r['source']['name']) && method_exists($r['source']['name'], 'getSourceColumn')) {
                    $class = call_user_func(array($manager, '__getClassName'), $r['handle']);
                    $section = Widget::TableData(call_user_func(array($class, 'getSourceColumn'), $r['handle']));
                } elseif (isset($r['source'], $r['source']['name'])) {
                    $section = Widget::TableData(stripslashes($r['source']['name']));
                } else {
                    $section = Widget::TableData(__('Unknown'), 'inactive');
                }

                // Attached pages
                $pages = ResourceManager::getAttachedPages($resource_type, $r['handle']);

                $pagelinks = [];
                $i = 0;

                foreach ($pages as $p) {
                    ++$i;
                    $pagelinks[] = Widget::Anchor(
                        General::sanitize($p['title']),
                        SYMPHONY_URL . '/blueprints/pages/edit/' . $p['id'] . '/'
                    )->generate() . (count($pages) > $i ? (($i % 10) == 0 ? '<br />' : ', ') : '');
                }

                $pages = implode('', $pagelinks);

                if ($pages == '') {
                    $pagelinks = Widget::TableData(__('None'), 'inactive');
                } else {
                    $pagelinks = Widget::TableData($pages, 'pages');
                }

                // Authors
                $author = $r['author']['name'];

                if ($author) {
                    if (isset($r['author']['website'])) {
                        $author = Widget::Anchor($r['author']['name'], General::validateURL($r['author']['website']));
                    } elseif (isset($r['author']['email'])) {
                        $author = Widget::Anchor($r['author']['name'], 'mailto:' . $r['author']['email']);
                    }
                }

                $author = Widget::TableData($author);
                $tableData = [$name, $section, $pagelinks, $author];

                /**
                 * Allows Extensions to inject custom table data for each Resource
                 * into the Resource Index
                 *
                 * @delegate AddCustomResourceColumnData
                 * @since Symphony 3.0.0
                 * @param string $context
                 *  '/blueprints/datasources/' or '/blueprints/events/'
                 * @param array $tableData
                 *  An array of `Widget::TableData`, passed by reference
                 * @param array $columns
                 *  An array of the current columns
                 * @param int $resource_type
                 *  The resource type, i.e. `ResourceManager::RESOURCE_TYPE_EVENT` or
                 *  `ResourceManager::RESOURCE_TYPE_DATASOURCE`.
                 * @param array $resource
                 *  The resource array
                 * @param string $action
                 *  The name of the action
                 * @param string $status
                 *  The status of the row
                 * @param bool $locked
                 *  If the resource is locked, i.e., read-only
                 */
                Symphony::ExtensionManager()->notifyMembers(
                    'AddCustomResourceColumnData', '/system/authors/', [
                        'tableData' => &$tableData,
                        'columns' => $columns,
                        'resource_type' => $resource_type,
                        'resource' => $r,
                        'action' => $action,
                        'status' => $status,
                        'locked' => $locked
                    ]
                );

                $aTableBody[] = Widget::TableRow($tableData, $status);
            }
        }

        $table = Widget::Table(
            Widget::TableHead($aTableHead),
            null,
            Widget::TableBody($aTableBody),
            'selectable',
            null,
            ['role' => 'directory', 'aria-labelledby' => 'symphony-subheading', 'data-interactive' => 'data-interactive']
        );

        $this->Form->appendChild($table);

        $version = new XMLElement('p', 'Symphony ' . Symphony::Configuration()->get('version', 'symphony'), [
            'id' => 'version'
        ]);
        $this->Form->appendChild($version);

        $tableActions = new XMLElement('div');
        $tableActions->setAttribute('class', 'actions');

        $options = [
            [null, false, __('With Selected...')],
            ['delete', false, __('Delete'), 'confirm'],
        ];

        $pages = $this->pagesFlatView();

        $group_attach = ['label' => __('Attach to Page'), 'options' => []];
        $group_detach = ['label' => __('Detach from Page'), 'options' => []];

        $group_attach['options'][] = ['attach-all-pages', false, __('All')];
        $group_detach['options'][] = ['detach-all-pages', false, __('All')];

        foreach ($pages as $p) {
            $group_attach['options'][] = ['attach-to-page-' . $p['id'], false, General::sanitize($p['title'])];
            $group_detach['options'][] = ['detach-from-page-' . $p['id'], false, General::sanitize($p['title'])];
        }

        $options[] = $group_attach;
        $options[] = $group_detach;

        /**
         * Allows an extension to modify the existing options for this page's
         * With Selected menu. If the `$options` parameter is an empty array,
         * the 'With Selected' menu will not be rendered.
         *
         * @delegate AddCustomActions
         * @since Symphony 2.3.2
         * @param string $context
         *  '/blueprints/datasources/' or '/blueprints/events/'
         * @param array $options
         *  An array of arrays, where each child array represents an option
         *  in the With Selected menu. Options should follow the same format
         *  expected by `Widget::__SelectBuildOption`. Passed by reference.
         */
        Symphony::ExtensionManager()->notifyMembers(
            'AddCustomActions', $context['pageroot'], [
            'options' => &$options
        ]);

        if (!empty($options)) {
            $tableActions->appendChild(Widget::Apply($options));
            $this->Form->appendChild($tableActions);
        }
    }

    /**
     * This function is called from the resources index when a user uses the
     * With Selected, or Apply, menu. The type of resource is given by
     * `$resource_type`. At this time the only two valid values,
     * `ResourceManager::RESOURCE_TYPE_EVENT` or `ResourceManager::RESOURCE_TYPE_DATASOURCE`.
     *
     * The function handles 'delete', 'attach', 'detach', 'attach all',
     * 'detach all' actions.
     *
     * @param integer $resource_type
     *  Either `ResourceManager::RESOURCE_TYPE_EVENT` or `ResourceManager::RESOURCE_TYPE_DATASOURCE`
     * @throws Exception
     */
    public function __actionIndex(int $resource_type = null)
    {
        $manager = ResourceManager::getManagerFromType($resource_type);
        $resource_name = str_replace('Manager', '', $manager);
        $delegate_path = strtolower($resource_name);
        $checked = (is_array($_POST['items'])) ? array_keys($_POST['items']) : null;
        $context = Administration::instance()->getPageCallback();

        if (isset($_POST['action']) && is_array($_POST['action'])) {
            /**
             * Extensions can listen for any custom actions that were added
             * through `AddCustomPreferenceFieldsets` or `AddCustomActions`
             * delegates.
             *
             * @delegate CustomActions
             * @since Symphony 2.3.2
             * @param string $context
             *  '/blueprints/datasources/' or '/blueprints/events/'
             * @param array $checked
             *  An array of the selected rows. The value is usually the ID of the
             *  the associated object.
             */
            Symphony::ExtensionManager()->notifyMembers(
                'CustomActions', $context['pageroot'], [
                'checked' => $checked
            ]);

            if (is_array($checked) && !empty($checked)) {
                if ($_POST['with-selected'] == 'delete') {
                    $canProceed = true;

                    foreach ($checked as $handle) {
                        $path = call_user_func(array($manager, '__getDriverPath'), $handle);

                        // Don't allow Extension resources to be deleted. RE: #2027
                        if (stripos($path, EXTENSIONS) === 0) {
                            continue;
                        }

                        /**
                         * Prior to deleting the Resource file. Target file path is provided.
                         *
                         * @since Symphony 3.0.0
                         * @param string $context
                         * '/blueprints/{$resource_name}/'
                         * @param string $file
                         *  The path to the Resource file
                         * @param string $handle
                         *  The handle of the Resource
                         */
                        Symphony::ExtensionManager()->notifyMembers(
                            "{$resource_name}PreDelete",
                            $context['pageroot'],
                            [
                                'file' => $path,
                                'handle' => $handle,
                            ]
                        );

                        if (!General::deleteFile($path)) {
                            $folder = str_replace(DOCROOT, '', $path);
                            $folder = str_replace('/' . basename($path), '', $folder);

                            $this->pageAlert(
                                __('Failed to delete %s.', ['<code>' . basename($path) . '</code>'])
                                . ' ' . __('Please check permissions on %s', ['<code>' . $folder . '</code>']),
                                Alert::ERROR
                            );
                            $canProceed = false;
                        } else {
                            $pages = ResourceManager::getAttachedPages($resource_type, $handle);

                            foreach ($pages as $page) {
                                ResourceManager::detach($resource_type, $handle, $page['id']);
                            }

                            /**
                             * After deleting the Resource file. Target file path is provided.
                             *
                             * @since Symphony 3.0.0
                             * @param string $context
                             * '/blueprints/{$resource_name}/'
                             * @param string $file
                             *  The path to the Resource file
                             * @param string $handle
                             *  The handle of the Resource
                             */
                            Symphony::ExtensionManager()->notifyMembers(
                                "{$resource_name}PostDelete",
                                "/blueprints/{$delegate_path}/",
                                [
                                    'file' => $path,
                                    'handle' => $handle,
                                ]
                            );
                        }
                    }

                    if ($canProceed) {
                        redirect(Administration::instance()->getCurrentPageURL());
                    }
                } elseif (preg_match('/^(at|de)?tach-(to|from)-page-/', $_POST['with-selected'])) {
                    if (substr($_POST['with-selected'], 0, 6) == 'detach') {
                        $page = str_replace('detach-from-page-', '', $_POST['with-selected']);

                        foreach ($checked as $handle) {
                            ResourceManager::detach($resource_type, $handle, $page);
                        }
                    } else {
                        $page = str_replace('attach-to-page-', '', $_POST['with-selected']);

                        foreach ($checked as $handle) {
                            ResourceManager::attach($resource_type, $handle, $page);
                        }
                    }

                    redirect(Administration::instance()->getCurrentPageURL());
                } elseif (preg_match('/^(at|de)?tach-all-pages$/', $_POST['with-selected'])) {
                    $pages = (new PageManager)->select(['id'])->execute()->rows();

                    if (substr($_POST['with-selected'], 0, 6) == 'detach') {
                        foreach ($checked as $handle) {
                            foreach ($pages as $page) {
                                ResourceManager::detach($resource_type, $handle, $page['id']);
                            }
                        }
                    } else {
                        foreach ($checked as $handle) {
                            foreach ($pages as $page) {
                                ResourceManager::attach($resource_type, $handle, $page['id']);
                            }
                        }
                    }

                    redirect(Administration::instance()->getCurrentPageURL());
                }
            }
        }
    }

    /**
     * Returns the path to the component-template by looking at the
     * `WORKSPACE/template/` directory, then at the `TEMPLATES`
     * directory for the convention `*.tpl`. If the template
     * is not found, false is returned
     *
     * @param string $name
     *  Name of the template
     * @return mixed
     *  String, which is the path to the template if the template is found,
     *  false otherwise
     */
    protected function getTemplate(string $name): string|bool
    {
        $format = '%s/%s.tpl';

        if (file_exists($template = sprintf($format, WORKSPACE . '/template', $name))) {
            return $template;
        } elseif (file_exists($template = sprintf($format, TEMPLATE, $name))) {
            return $template;
        } else {
            return false;
        }
    }
}

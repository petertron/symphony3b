<?php

/**
 * @package SectionFields
 */

namespace Symnext\SectionFields;

use Symnext\Toolkit\Field;
use Symnext\Toolkit\XMLElement;
use Symnext\Interface\ImportableField;
use Symnext\Interface\ExportableField;
use Symnext\Database\EntryQueryListAdapter;

/**
 * The Tag List field is really a different interface for the Select Box
 * field, offering a tag interface that can have static suggestions,
 * suggestions from another field or a dynamic list based on what an Author
 * has previously used for this field.
 */
class FieldTagList extends Field implements ExportableField, ImportableField
{
    const DEFAULT_LOCATION = 'main';

    static $table_columns = [
        'handle' => [
            'VARCHAR(255)',
            'NULL',
        ],
        'value' => [
            'VARCHAR(255)',
            'NULL',
        ],
        "KEY (<handle>)",
        "KEY (<value>)"
    ];

    public function __construct()
    {
        parent::__construct();
        $this->name = __('Tag List');
        $this->_required = true;
        $this->_showassociation = true;
        $this->entryQueryFieldAdapter = new EntryQueryListAdapter($this);
        $this->initialiseSettings([
            'pre_populate_source' => [
                'type' => 'array'
            ],
            'sort' => [
                'type' => 'string',
                'values_allowed' => ['yes', 'no'],
                'default_value' => 'no'
            ],
            'required' => [
                'type' => 'string',
                'values_allowed' => ['yes', 'no'],
                'default_value' => 'no'
            ],
            'show_column' => [
                'type' => 'string',
                'values_allowed' => ['yes', 'no'],
                'default_value' => 'no'
            ]
        ]);
    }

    /*-------------------------------------------------------------------------
        Definition:
    -------------------------------------------------------------------------*/

    public function canFilter(): bool
    {
        return true;
    }

    public function canPrePopulate(): bool
    {
        return true;
    }

    public function requiresSQLGrouping(): bool
    {
        return true;
    }

    public function allowDatasourceParamOutput(): bool
    {
        return true;
    }

    public function fetchSuggestionTypes(): array
    {
        return ['association', 'static'];
    }

    /*-------------------------------------------------------------------------
        Setup:
    -------------------------------------------------------------------------*/

    /*-------------------------------------------------------------------------
        Utilities:
    -------------------------------------------------------------------------*/

    public function fetchAssociatedEntryCount($value): int
    {
        $value = array_map('trim', array_map([$this, 'cleanValue'], explode(',', $value)));

        return Symphony::Database()
            ->select()
            ->count('handle')
            ->from('tbl_entries_data_' . $this->get('id'))
            ->where(['handle' => ['in' => $value]])
            ->execute()
            ->integer(0);
    }

    public function fetchAssociatedEntrySearchValue(
        array $data,
        int $field_id = null,
        int $parent_entry_id = null
    ): string|array
    {
        if (!is_array($data)) {
            return $data;
        }

        if (!is_array($data['handle'])) {
            $data['handle'] = [$data['handle']];
            $data['value'] = [$data['value']];
        }

        return implode(',', $data['handle']);
    }

    /**
     * Find all the entries that reference this entry's tags.
     *
     * @param integer $entry_id
     * @param integer $parent_field_id
     * @return array
     */
    public function findRelatedEntries(
        int $entry_id,
        int $parent_field_id
    ): array
    {
        // We have the entry_id of the entry that has the referenced tag values
        // Lets find out what those handles are so we can then referenced the
        // child section looking for them.
        $handles = Symphony::Database()
            ->select(['handle'])
            ->from("tbl_entries_data_$parent_field_id")
            ->where(['entry_id' => $entry_id])
            ->execute()
            ->column('handle');

        if (empty($handles)) {
            return [];
        }

        $ids = Symphony::Database()
            ->select(['entry_id'])
            ->from('tbl_entries_data_' . $this->get('id'))
            ->where(['handle' => ['in' => $handles]])
            ->execute()
            ->column('entry_id');

        return $ids;
    }

    /**
     * Find all the entries that contain the tags that have been referenced
     * from this field own entry.
     *
     * @param integer $field_id
     * @param integer $entry_id
     * @return array
     */
    public function findParentRelatedEntries(
        int $field_id,
        int $entry_id
    ): array
    {
        // Get all the `handles` that have been referenced from the
        // child association.
        $handles = Symphony::Database()
            ->select(['handle'])
            ->from('tbl_entries_data_' . $this->get('id'))
            ->where(['entry_id' => $entry_id])
            ->execute()
            ->column('handle');

        // Now find the associated entry ids for those `handles` in
        // the parent section.
        $ids = Symphony::Database()
            ->select(['entry_id'])
            ->from("tbl_entries_data_$field_id")
            ->where(['handle' => ['in' => $handles]])
            ->execute()
            ->column('entry_id');

        return $ids;
    }

    public function set(string $field, $value): void
    {
        if ($field == 'pre_populate_source' && !is_array($value)) {
            $value = preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }
        parent::set($field, $value);
    }

    public function getToggleStates(): array
    {
        if (!is_array($this->get('pre_populate_source'))) {
            return [];
        }

        $values = [];

        foreach ($this->get('pre_populate_source') as $item) {
            if ($item === 'none') {
                break;
            }

            $result = Symphony::Database()
                ->select(['value'])
                ->distinct()
                ->from('tbl_entries_data_' . ($item == 'existing' ? $this->get('id') : $item))
                ->orderBy(['value' => 'ASC'])
                ->execute()
                ->column('value');

            if (!is_array($result) || empty($result)) {
                continue;
            }

            $values = array_merge($values, $result);
        }

        return array_unique($values);
    }

    private static function __tagArrayToString(array $tags): string
    {
        if (empty($tags)) {
            return null;
        }

        sort($tags);

        return implode(', ', $tags);
    }

    /*-------------------------------------------------------------------------
        Settings:
    -------------------------------------------------------------------------*/

    public static function addValuesToXMLDoc(
        XMLElement $x_parent,
        array $values
    ): void
    {
        $x_parent->appendElement('required', $values['required'] ?? 'no');
        $x_parent->appendElement('show_column', $values['show_column'] ?? 'no');
    }

    public function findDefaults(array &$settings): void
    {
        if (!isset($settings['pre_populate_source'])) {
            $settings['pre_populate_source'] = ['existing'];
        }

        if (!isset($settings['show_association'])) {
            $settings['show_association'] = 'no';
        }
    }

    public function displaySettingsPanel(
        XMLElement &$wrapper,
        $errors = null
    ): void
    {
        parent::displaySettingsPanel($wrapper, $errors);

        // Suggestions
        $label = Widget::Label(__('Suggestion List'));

        $sections = (new SectionManager)->select()->execute()->rows();
        $field_groups = [];

        foreach ($sections as $section) {
            $field_groups[$section->get('id')] = ['fields' => $section->fetchFields(), 'section' => $section];
        }

        $pre_populate_source = $this->get('pre_populate_source') ?? [];
        $options = [
            ['none', in_array('none', $pre_populate_source), __('No Suggestions')],
            ['existing', in_array('existing', $pre_populate_source), __('Existing Values')],
        ];

        foreach ($field_groups as $group) {
            if (!is_array($group['fields'])) {
                continue;
            }

            $fields = [];

            foreach ($group['fields'] as $f) {
                if ($f->get('id') != $this->get('id') && $f->canPrePopulate()) {
                    $fields[] = [$f->get('id'), (in_array($f->get('id'), $pre_populate_source)), $f->get('label')];
                }
            }

            if (is_array($fields) && !empty($fields)) {
                $options[] = ['label' => $group['section']->get('name'), 'options' => $fields];
            }
        }

        $label->appendChild(Widget::Select('fields['.$this->get('sortorder').'][pre_populate_source][]', $options, ['multiple' => 'multiple']));
        $wrapper->appendChild($label);

        // Validation rule
        $this->buildValidationSelect($wrapper, $this->get('validator'), 'fields['.$this->get('sortorder').'][validator]', 'input', $errors);

        // Associations
        $fieldset = new XMLElement('fieldset');
        $this->appendAssociationInterfaceSelect($fieldset);
        $this->appendShowAssociationCheckbox($fieldset);
        $wrapper->appendChild($fieldset);

        // Requirements and table display
        $this->appendStatusFooter($wrapper);
    }

    public function commit(): bool
    {
        if (!parent::commit()) {
            return false;
        }

        $id = $this->get('id');

        if ($id === false) {
            return false;
        }

        $fields = [];

        $fields['pre_populate_source'] = (is_null($this->get('pre_populate_source')) ? 'none' : implode(',', $this->get('pre_populate_source')));
        //$fields['validator'] = ($fields['validator'] == 'custom' ? null : $this->get('validator'));
        $fields['validator'] = $this->get('validator');

        if (!FieldManager::saveSettings($id, $fields)) {
            return false;
        }

        SectionManager::removeSectionAssociation($id);

        if (is_array($this->get('pre_populate_source'))) {
            foreach ($this->get('pre_populate_source') as $field_id) {
                if ($field_id === 'none' || $field_id === 'existing') {
                    continue;
                }

                if (!is_null($field_id) && is_numeric($field_id)) {
                    SectionManager::createSectionAssociation(null, $id, (int) $field_id, $this->get('show_association') === 'yes' ? true : false, $this->get('association_ui'), $this->get('association_editor'));
                }
            }
        }

        return true;
    }

    /*-------------------------------------------------------------------------
        Publish:
    -------------------------------------------------------------------------*/

    public function displayPublishPanel(
        XMLElement &$wrapper,
        array $data = null,
        $flagWithError = null,
        string $fieldnamePrefix = null,
        string $fieldnamePostfix = null,
        int $entry_id = null
    ): void
    {
        $value = null;

        if (isset($data['value'])) {
            $value = (is_array($data['value']) ? self::__tagArrayToString($data['value']) : $data['value']);
        }

        $label = Widget::Label($this->get('label'));

        if ($this->get('required') !== 'yes') {
            $label->appendChild(new XMLElement('i', __('Optional')));
        }

        $label->appendChild(
            Widget::Input('fields'.$fieldnamePrefix.'['.$this->get('element_name').']'.$fieldnamePostfix, (strlen($value) != 0 ? General::sanitize($value) : null))
        );

        if ($flagWithError != null) {
            $wrapper->appendChild(Widget::Error($label, $flagWithError));
        } else {
            $wrapper->appendChild($label);
        }

        if ($this->get('pre_populate_source') != null) {
            $existing_tags = $this->getToggleStates();

            if (is_array($existing_tags) && !empty($existing_tags)) {
                $taglist = new XMLElement('ul');
                $taglist->setAttribute('class', 'tags');
                $taglist->setAttribute('data-interactive', 'data-interactive');

                foreach ($existing_tags as $tag) {
                    $taglist->appendChild(
                        new XMLElement('li', General::sanitize($tag))
                    );
                }

                $wrapper->appendChild($taglist);
            }
        }
    }

    private function parseUserSubmittedData(string|array $data): array
    {
        if (!is_array($data)) {
            $data = preg_split('/\,\s*/i', $data, -1, PREG_SPLIT_NO_EMPTY);
        }
        return array_filter(array_map('trim', $data));
    }

    public function checkPostFieldData(
        array $data,
        string &$message,
        int $entry_id = null
    ): int
    {
        $message = null;

        if ($this->get('required') === 'yes' && strlen(trim($data)) == 0) {
            $message = __('‘%s’ is a required field.', [$this->get('label')]);
            return self::__MISSING_FIELDS__;
        }

        if ($this->get('validator')) {
            $data = $this->parseUserSubmittedData($data);

            if (empty($data)) {
                return self::__OK__;
            }

            if (!General::validateString($data, $this->get('validator'))) {
                $message = __("'%s' contains invalid data. Please check the contents.", [$this->get('label')]);
                return self::__INVALID_FIELDS__;
            }
        }

        return self::__OK__;
    }

    public function processRawFieldData(
        $data,
        int &$status,
        string &$message = null,
        bool $simulate = false,
        int $entry_id = null
    ): array
    {
        $status = self::__OK__;

        $data = $this->parseUserSubmittedData($data);

        if (empty($data)) {
            return null;
        }

        // Do a case insensitive removal of duplicates
        $data = General::array_remove_duplicates($data, true);

        sort($data);

        $result = [];
        foreach ($data as $value) {
            $result['value'][] = $value;
            $result['handle'][] = Lang::createHandle($value);
        }

        return $result;
    }

    /*-------------------------------------------------------------------------
        Output:
    -------------------------------------------------------------------------*/

    public function appendFormattedElement(
        XMLElement &$wrapper,
        array|string $data,
        bool $encode = false,
        string $mode = null,
        int $entry_id = null
    ): void
    {
        if (!is_array($data) || empty($data) || is_null($data['value'])) {
            return;
        }

        $list = new XMLElement($this->get('element_name'));

        if (!is_array($data['handle']) && !is_array($data['value'])) {
            $data = [
                'handle' => [$data['handle']],
                'value' => [$data['value']]
            ];
        }

        foreach ($data['value'] as $index => $value) {
            $list->appendChild(new XMLElement('item', General::sanitize($value), [
                'handle' => $data['handle'][$index]
            ]));
        }

        $wrapper->appendChild($list);
    }

    public function prepareTextValue(
        $data, int $entry_id = null): ?string
    {
        if (!is_array($data) || empty($data)) {
            return '';
        }

        $value = '';

        if (isset($data['value'])) {
            $value = (is_array($data['value']) ? self::__tagArrayToString($data['value']) : $data['value']);
        }

        return General::sanitize($value);
    }

    public function getParameterPoolValue(
        array $data,
        int $entry_id = null
    ): array|string
    {
        return $this->prepareExportValue($data, ExportableField::LIST_OF + ExportableField::HANDLE, $entry_id);
    }

    /*-------------------------------------------------------------------------
        Import:
    -------------------------------------------------------------------------*/

    public function getImportModes(): array
    {
        return [
            'getValue' =>       ImportableField::STRING_VALUE,
            'getPostdata' =>    ImportableField::ARRAY_VALUE
        ];
    }

    public function prepareImportValue(
        $data,
        int $mode,
        int $entry_id = null
    )
    {
        $message = $status = null;
        $modes = (object)$this->getImportModes();

        if (is_array($data)) {
            $data = implode(', ', $data);
        }

        if ($mode === $modes->getValue) {
            return $data;
        } elseif ($mode === $modes->getPostdata) {
            return $this->processRawFieldData($data, $status, $message, true, $entry_id);
        }

        return null;
    }

    /*-------------------------------------------------------------------------
        Export:
    -------------------------------------------------------------------------*/

    /**
     * Return a list of supported export modes for use with `prepareExportValue`.
     *
     * @return array
     */
    public function getExportModes(): array
    {
        return [
            'listHandle' =>         ExportableField::LIST_OF
                                    + ExportableField::HANDLE,
            'listValue' =>          ExportableField::LIST_OF
                                    + ExportableField::VALUE,
            'listHandleToValue' =>  ExportableField::LIST_OF
                                    + ExportableField::HANDLE
                                    + ExportableField::VALUE,
            'getPostdata' =>        ExportableField::POSTDATA
        ];
    }

    /**
     * Give the field some data and ask it to return a value using one of many
     * possible modes.
     *
     * @param mixed $data
     * @param integer $mode
     * @param integer $entry_id
     * @return array|null
     */
    public function prepareExportValue(
        $data,
        int $mode,
        int$entry_id = null
    ): ?array
    {
        $modes = (object)$this->getExportModes();

        if (isset($data['handle']) && is_array($data['handle']) === false) {
            $data['handle'] = [$data['handle']];
        }

        if (isset($data['value']) && is_array($data['value']) === false) {
            $data['value'] = [$data['value']];
        }

        // Handle => value pairs:
        if ($mode === $modes->listHandleToValue) {
            return isset($data['handle'], $data['value'])
                ? array_combine($data['handle'], $data['value'])
                : [];

            // Array of handles:
        } elseif ($mode === $modes->listHandle) {
            return isset($data['handle'])
                ? $data['handle']
                : [];

            // Array of values:
        } elseif ($mode === $modes->listValue) {
            return isset($data['value'])
                ? $data['value']
                : [];

            // Comma seperated values:
        } elseif ($mode === $modes->getPostdata) {
            return isset($data['value'])
                ? implode(', ', $data['value'])
                : null;
        }
    }

    /*-------------------------------------------------------------------------
        Filtering:
    -------------------------------------------------------------------------*/

    public function displayFilteringOptions(XMLElement &$wrapper): void
    {
        if ($this->get('pre_populate_source') != null) {
            $existing_tags = $this->getToggleStates();

            if (is_array($existing_tags) && !empty($existing_tags)) {
                $taglist = new XMLElement('ul');
                $taglist->setAttribute('class', 'tags');
                $taglist->setAttribute('data-interactive', 'data-interactive');

                foreach ($existing_tags as $tag) {
                    $taglist->appendChild(
                        new XMLElement('li', General::sanitize($tag))
                    );
                }

                $wrapper->appendChild($taglist);
            }
        }
    }

    public function fetchFilterableOperators(): array
    {
        return [
            [
                'title' => 'is',
                'filter' => ' ',
                'help' => __('Find values that are an exact match for the given string.')
            ],
            [
                'filter' => 'sql: NOT NULL',
                'title' => 'is not empty',
                'help' => __('Find entries where any value is selected.')
            ],
            [
                'filter' => 'sql: NULL',
                'title' => 'is empty',
                'help' => __('Find entries where no value is selected.')
            ],
            [
                'filter' => 'sql-null-or-not: ',
                'title' => 'is empty or not',
                'help' => __('Find entries where no value is selected or it is not equal to this value.')
            ],
            [
                'filter' => 'not: ',
                'title' => 'is not',
                'help' => __('Find entries where the value is not equal to this value.')
            ],
            [
                'filter' => 'regexp: ',
                'title' => 'contains',
                'help' => __('Find entries where the value matches the regex.')
            ],
            [
                'filter' => 'not-regexp: ',
                'title' => 'does not contain',
                'help' => __('Find entries where the value does not match the regex.')
            ]
        ];
    }

    /**
     * @deprecated @since Symphony 3.0.0
     * @see Field::buildDSRetrievalSQL()
     */
    public function buildDSRetrievalSQL(
        $data,
        string &$joins,
        string &$where,
        bool $andOperation = false
    ): bool
    {
        if (Symphony::Log()) {
            Symphony::Log()->pushDeprecateWarningToLog(
                get_called_class() . '::buildDSRetrievalSQL()',
                'EntryQueryFieldAdapter::filter()'
            );
        }
        $field_id = $this->get('id');

        if (self::isFilterRegex($data[0])) {
            $this->buildRegexSQL($data[0], ['value', 'handle'], $joins, $where);
        } elseif (self::isFilterSQL($data[0])) {
            $this->buildFilterSQL($data[0], ['value', 'handle'], $joins, $where);
        } else {
            $negation = false;
            $null = false;
            if (preg_match('/^not:/', $data[0])) {
                $data[0] = preg_replace('/^not:/', null, $data[0]);
                $negation = true;
            } elseif (preg_match('/^sql-null-or-not:/', $data[0])) {
                $data[0] = preg_replace('/^sql-null-or-not:/', null, $data[0]);
                $negation = true;
                $null = true;
            }

            foreach ($data as &$value) {
                $value = $this->cleanValue($value);
            }

            if ($andOperation) {
                $condition = ($negation) ? '!=' : '=';
                foreach ($data as $key => $bit) {
                    $joins .= " LEFT JOIN `tbl_entries_data_$field_id` AS `t{$field_id}_{$this->_key}` ON (`e`.`id` = `t{$field_id}_{$this->_key}`.entry_id) ";
                    $where .= " AND ((
                                        t{$field_id}_{$this->_key}.value $condition '$bit'
                                        OR t{$field_id}_{$this->_key}.handle $condition '$bit'
                                    )";

                    if ($null) {
                        $where .= " OR `t{$field_id}_{$this->_key}`.`value` IS NULL) ";
                    } else {
                        $where .= ") ";
                    }
                    $this->_key++;
                }
            } else {
                $data = "'".implode("', '", $data)."'";

                // Apply a different where condition if we are using $negation. RE: #29
                if ($negation) {
                    $condition = 'NOT EXISTS';
                    $where .= " AND $condition (
                        SELECT *
                        FROM `tbl_entries_data_$field_id` AS `t{$field_id}_{$this->_key}`
                        WHERE `t{$field_id}_{$this->_key}`.entry_id = `e`.id AND (
                            `t{$field_id}_{$this->_key}`.handle IN ($data) OR
                            `t{$field_id}_{$this->_key}`.value IN ($data)
                        )
                    )";
                } else {

                    // Normal filtering
                    $joins .= " LEFT JOIN `tbl_entries_data_$field_id` AS `t{$field_id}_{$this->_key}` ON (`e`.`id` = `t{$field_id}_{$this->_key}`.entry_id) ";
                    $where .= " AND (
                                    t{$field_id}_{$this->_key}.value IN ($data)
                                    OR t{$field_id}_{$this->_key}.handle IN ($data)
                                ";

                    // If we want entries with null values included in the result
                    $where .= ($null) ? " OR `t{$field_id}_{$this->_key}`.`relation_id` IS NULL) " : ") ";
                }
            }
        }

        return true;
    }
}

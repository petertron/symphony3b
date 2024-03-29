<?php

/**
 * @package SectionFields
 */

namespace Symnext\SectionFields;

use Symnext\Toolkit\Field;
use Symnext\Toolkit\XMLElement;
use Symnext\Toolkit\Entry;
use Symnext\Interface\ImportableField;
use Symnext\Interface\ExportableField;
use Symnext\Database\EntryQueryUploadAdapter;

/**
 * A simple Upload field that essentially maps to HTML's `<input type='file '/>`.
 */
class FieldUpload extends Field implements ExportableField, ImportableField
{
    protected static $imageMimeTypes = [
        'image/gif',
        'image/jpg',
        'image/jpeg',
        'image/pjpeg',
        'image/png',
        'image/x-png'
    ];

    const DEFAULT_LOCATION = 'sidebar';

    const DEFAULT_SETTINGS= [
        'required' => 'no',
        'show_column' => 'no',
    ];

    static $table_columns = [
        'file' => [
            'VARCHAR(255)',
            'NULL'
        ],
        'size' => [
            'INT',
            'NULL',
        ],
        'mimetype' => [
            'VARCHAR(100)',
            'NULL'
        ],
        'meta' => [
            'VARCHAR(255)',
            'NULL'
        ],
        'KEY(<file>)',
        'KEY(<mimetype>)'
    ];

    public function __construct()
    {
        parent::__construct();

        $this->name = __('File Upload');
        $this->_required = true;
        $this->entryQueryFieldAdapter = new EntryQueryUploadAdapter($this);
        $this->initialiseSettings([
            'required' => [
                'type' => 'string',
                'values_allowed' => ['yes', 'no'],
                'default_value' => 'no'
            ],
            'show_column' => [
                'type' => 'string',
                'values_allowed' => ['yes', 'no'],
                'default_value' => 'yes'
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

    public function isSortable(): bool
    {
        return true;
    }

    public function fetchFilterableOperators(): array
    {
        return [
            [
                'title' => 'is',
                'filter' => ' ',
                'help' => __('Find files that are an exact match for the given string.')
            ],
            [
                'filter' => 'sql: NOT NULL',
                'title' => 'is not empty',
                'help' => __('Find entries where a file has been saved.')
            ],
            [
                'filter' => 'sql: NULL',
                'title' => 'is empty',
                'help' => __('Find entries where no file has been saved.')
            ],
            [
                'title' => 'contains',
                'filter' => 'regexp: ',
                'help' => __('Find files that match the given <a href="%s">MySQL regular expressions</a>.', [
                    'https://dev.mysql.com/doc/mysql/en/regexp.html'
                ])
            ],
            [
                'title' => 'does not contain',
                'filter' => 'not-regexp: ',
                'help' => __('Find files that do not match the given <a href="%s">MySQL regular expressions</a>.', [
                    'https://dev.mysql.com/doc/mysql/en/regexp.html'
                ])
            ],
            [
                'title' => 'file type is',
                'filter' => 'mimetype: ',
                'help' => __('Find files that match the given mimetype.')
            ],
            [
                'title' => 'size is',
                'filter' => 'size: ',
                'help' => __('Find files that match the given size.')
            ]
        ];
    }


    /*-------------------------------------------------------------------------
        Utilities:
    -------------------------------------------------------------------------*/

    public function entryDataCleanup(
        array|int $entry_id,
        array $data = null
    ): bool
    {
        if (is_null($data)) {
            return true;
        }

        $file_location = $this->getFilePath($data['file']);

        if (is_file($file_location)) {
            General::deleteFile($file_location);
        }

        parent::entryDataCleanup($entry_id);

        return true;
    }

    public static function getMetaInfo(
        string $file, string $type
    ): array
    {
        $meta = [];

        if (!file_exists($file) || !is_readable($file)) {
            return $meta;
        }

        $meta['creation'] = DateTimeObj::get('c', filemtime($file));

        if (General::in_iarray($type, FieldUpload::$imageMimeTypes) && $array = getimagesize($file)) {
            $meta['width'] = $array[0];
            $meta['height'] = $array[1];
        }

        return $meta;
    }

    public function getFilePath(string $filename): string
    {
        /**
         * Ensure the file exists in the `WORKSPACE` directory
         * @link http://getsymphony.com/discuss/issues/view/610/
         */
        $file = WORKSPACE . preg_replace(array('%/+%', '%(^|/)\.\./%', '%\/workspace\/%'), '/', $this->get('destination') . '/' . $filename);

        return $file;
    }

    /*-------------------------------------------------------------------------
        Settings:
    -------------------------------------------------------------------------*/

    public static function addValuesToXMLDoc(
        XMLElement $x_parent,
        array $values
    ): void
    {
        $x_parent->appendElement('validation', $values['validation'] ?? '');
        $x_parent->appendElement('required', $values['required'] ?? 'no');
        $x_parent->appendElement('show_column', $values['show_column'] ?? 'no');
    }

    public function displaySettingsPanel(
        XMLElement &$wrapper,
        $errors = null
    ): void
    {
        parent::displaySettingsPanel($wrapper, $errors);

        // Destination Folder
        $ignore = [
            '/workspace/events',
            '/workspace/data-sources',
            '/workspace/text-formatters',
            '/workspace/pages',
            '/workspace/utilities'
        ];
        $directories = General::listDirStructure(WORKSPACE, null, true, DOCROOT, $ignore);

        $label = Widget::Label(__('Destination Directory'));

        $options = [];
        $options[] = ['/workspace', false, '/workspace'];

        if (!empty($directories) && is_array($directories)) {
            foreach ($directories as $d) {
                $d = '/' . trim($d, '/');

                if (!in_array($d, $ignore)) {
                    $options[] = [$d, ($this->get('destination') == $d), $d];
                }
            }
        }

        $label->appendChild(Widget::Select('fields['.$this->get('sortorder').'][destination]', $options));

        if (isset($errors['destination'])) {
            $wrapper->appendChild(Widget::Error($label, $errors['destination']));
        } else {
            $wrapper->appendChild($label);
        }

        // Validation rule
        $this->buildValidationSelect($wrapper, $this->get('validator'), 'fields['.$this->get('sortorder').'][validator]', 'upload', $errors);

        // Requirements and table display
        $this->appendStatusFooter($wrapper);
    }

    public function checkFields(
        array &$errors,
        bool $checkForDuplicates = true
    ): int
    {
        if (is_dir(DOCROOT . $this->get('destination') . '/') === false) {
            $errors['destination'] = __('The destination directory, %s, does not exist.', [
                '<code>' . $this->get('destination') . '</code>'
            ]);
        } elseif (is_writable(DOCROOT . $this->get('destination') . '/') === false) {
            $errors['destination'] = __('The destination directory is not writable.')
                . ' '
                . __('Please check permissions on %s.', [
                    '<code>' . $this->get('destination') . '</code>'
                ]);
        }

        parent::checkFields($errors, $checkForDuplicates);
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
        $fields['destination'] = $this->get('destination');
        //$fields['validator'] = ($fields['validator'] == 'custom' ? null : $this->get('validator'));
        $fields['validator'] = $this->get('validator');

        return FieldManager::saveSettings($id, $fields);
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
        if (is_dir(DOCROOT . $this->get('destination') . '/') === false) {
            $flagWithError = __('The destination directory, %s, does not exist.', [
                '<code>' . $this->get('destination') . '</code>'
            ]);
        } elseif ($flagWithError && is_writable(DOCROOT . $this->get('destination') . '/') === false) {
            $flagWithError = __('Destination folder is not writable.')
                . ' '
                . __('Please check permissions on %s.', [
                    '<code>' . $this->get('destination') . '</code>'
                ]);
        }

        $label = Widget::Label($this->get('label'));
        $label->setAttribute('class', 'file');

        if ($this->get('required') !== 'yes') {
            $label->appendChild(new XMLElement('i', __('Optional')));
        }

        $span = new XMLElement('span', null, ['class' => 'frame']);

        if (isset($data['file'])) {
            $filename = $this->get('destination') . '/' . basename($data['file']);
            $file = $this->getFilePath($data['file']);
            if (file_exists($file) === false || !is_readable($file)) {
                $flagWithError = __('The file uploaded is no longer available. Please check that it exists, and is readable.');
            }

            $span->appendChild(new XMLElement('span', Widget::Anchor(preg_replace("![^a-z0-9]+!i", "$0&#8203;", $filename), URL . $filename)));
        } else {
            $filename = null;
        }

        $span->appendChild(Widget::Input('fields'.$fieldnamePrefix.'['.$this->get('element_name').']'.$fieldnamePostfix, $filename, ($filename ? 'hidden' : 'file')));

        $label->appendChild($span);

        if ($flagWithError != null) {
            $wrapper->appendChild(Widget::Error($label, $flagWithError));
        } else {
            $wrapper->appendChild($label);
        }
    }

    public function validateFilename($file, &$message)
    {
        if ($this->get('validator') != null) {
            $rule = $this->get('validator');

            if (General::validateString($file, $rule) === false) {
                $message = __('File chosen in ‘%s’ does not match allowable file types for that field.', [
                    $this->get('label')
                ]);

                return self::__INVALID_FIELDS__;
            }
        }
        // If the developer did not specified any validator, check for the
        // blacklisted file types instead
        else {
            $blacklist = Symphony::Configuration()->get('upload_blacklist', 'admin');

            if (!empty($blacklist) && General::validateString($file, $blacklist)) {
                $message = __('File chosen in ‘%s’ is blacklisted for that field.', [
                    $this->get('label')
                ]);

                return self::__INVALID_FIELDS__;
            }
        }

        return self::__OK__;
    }

    public function checkPostFieldData(
        array $data,
        string &$message,
        int $entry_id = null
    ): int
    {
        /**
         * For information about PHPs upload error constants see:
         * @link http://php.net/manual/en/features.file-upload.errors.php
         */
        $message = null;

        if (
            empty($data)
            || (
                is_array($data)
                && isset($data['error'])
                && $data['error'] == UPLOAD_ERR_NO_FILE
            )
        ) {
            if ($this->get('required') === 'yes') {
                $message = __('‘%s’ is a required field.', [$this->get('label')]);

                return self::__MISSING_FIELDS__;
            }

            return self::__OK__;
        }

        // Its not an array, so just retain the current data and return
        if (is_array($data) === false) {
            $file = $this->getFilePath(basename($data));
            if (file_exists($file) === false || !is_readable($file)) {
                $message = __('The file uploaded is no longer available. Please check that it exists, and is readable.');

                return self::__INVALID_FIELDS__;
            }

            // Ensure that the file still matches the validator and hasn't
            // changed since it was uploaded.
            return $this->validateFilename($file, $message);
        }

        if (is_dir(DOCROOT . $this->get('destination') . '/') === false) {
            $message = __('The destination directory, %s, does not exist.', [
                '<code>' . $this->get('destination') . '</code>'
            ]);

            return self::__ERROR__;
        } elseif (is_writable(DOCROOT . $this->get('destination') . '/') === false) {
            $message = __('Destination folder is not writable.')
                . ' '
                . __('Please check permissions on %s.', [
                    '<code>' . $this->get('destination') . '</code>'
                ]);

            return self::__ERROR__;
        }

        if ($data['error'] != UPLOAD_ERR_NO_FILE && $data['error'] != UPLOAD_ERR_OK) {
            switch ($data['error']) {
                case UPLOAD_ERR_INI_SIZE:
                    $message = __('File chosen in ‘%1$s’ exceeds the maximum allowed upload size of %2$s specified by your host.', [$this->get('label'), (is_numeric(ini_get('upload_max_filesize')) ? General::formatFilesize(ini_get('upload_max_filesize')) : ini_get('upload_max_filesize'))]);
                    break;
                case UPLOAD_ERR_FORM_SIZE:
                    $message = __('File chosen in ‘%1$s’ exceeds the maximum allowed upload size of %2$s, specified by Symphony.', [$this->get('label'), General::formatFilesize($_POST['MAX_FILE_SIZE'])]);
                    break;
                case UPLOAD_ERR_PARTIAL:
                case UPLOAD_ERR_NO_TMP_DIR:
                    $message = __('File chosen in ‘%s’ was only partially uploaded due to an error.', [$this->get('label')]);
                    break;
                case UPLOAD_ERR_CANT_WRITE:
                    $message = __('Uploading ‘%s’ failed. Could not write temporary file to disk.', [$this->get('label')]);
                    break;
                case UPLOAD_ERR_EXTENSION:
                    $message = __('Uploading ‘%s’ failed. File upload stopped by extension.', [$this->get('label')]);
                    break;
            }

            return self::__ERROR_CUSTOM__;
        }

        // Sanitize the filename
        $data['name'] = Lang::createFilename($data['name']);

        // Validate the filename
        return $this->validateFilename($data['name'], $message);
    }

    public function processRawFieldData(
        array|string $data,
        int &$status,
        string &$message = null,
        bool $simulate = false,
        int $entry_id = null
    ): array
    {
        $status = self::__OK__;

        $result = [
            'file' =>       null,
            'mimetype' =>   null,
            'size' =>       null,
            'meta' =>       null
        ];

        // No file given, save empty data:
        if ($data === null) {
        }

        // Its not an array, so just retain the current data and return:
        if (!is_array($data)) {
            $file = $this->getFilePath(basename($data));

            $result['file'] = $data;

            // Grab the existing entry data to preserve the MIME type and size information
            if (isset($entry_id)) {
                $row = $this->getCurrentValues($entry_id);

                if (empty($row) === false) {
                    $result = $row;
                }
            }

            // Found the file, add any missing meta information:
            if (file_exists($file) && is_readable($file)) {
                if (empty($result['mimetype'])) {
                    $result['mimetype'] = General::getMimeType($file);
                }

                if (empty($result['size'])) {
                    $result['size'] = filesize($file);
                }

                if (empty($result['meta'])) {
                    $result['meta'] = serialize(static::getMetaInfo($file, $result['mimetype']));
                }

                // The file was not found, or is unreadable:
            } else {
                $message = __('The file uploaded is no longer available. Please check that it exists, and is readable.');
                $status = self::__INVALID_FIELDS__;
            }

            return $result;
        }

        if ($simulate && is_null($entry_id)) {
            return $data;
        }

        // Check to see if the entry already has a file associated with it:
        if (is_null($entry_id) === false) {
            $row = $this->getCurrentValues($entry_id);

            $existing_file = isset($row['file']) ? $this->getFilePath($row['file']) : null;

            // File was removed:
            if (
                $data['error'] == UPLOAD_ERR_NO_FILE
                && !is_null($existing_file)
                && is_file($existing_file)
            ) {
                General::deleteFile($existing_file);
            }
        }

        // Do not continue on upload error:
        if ($data['error'] == UPLOAD_ERR_NO_FILE || $data['error'] != UPLOAD_ERR_OK) {
            return false;
        }

        // Where to upload the new file?
        $abs_path = DOCROOT . '/' . trim($this->get('destination'), '/');
        $rel_path = str_replace('/workspace', '', $this->get('destination'));

        // Sanitize the filename
        $data['name'] = Lang::createFilename($data['name']);

        // If a file already exists, then rename the file being uploaded by
        // adding `_1` to the filename. If `_1` already exists, the logic
        // will keep adding 1 until a filename is available (#672)
        if (file_exists($abs_path . '/' . $data['name'])) {
            $extension = General::getExtension($data['name']);
            $new_file = substr($abs_path . '/' . $data['name'], 0, -1 - strlen($extension));
            $count = 1;

            do {
                $renamed_file = $new_file . '_' . $count . '.' . $extension;
                $count++;
            } while (file_exists($renamed_file));

            // Extract the name filename from `$renamed_file`.
            $data['name'] = str_replace($abs_path . '/', '', $renamed_file);
        }

        $file = $this->getFilePath($data['name']);

        // Attempt to upload the file:
        $uploaded = General::uploadFile(
            $abs_path,
            $data['name'],
            $data['tmp_name'],
            Symphony::Configuration()->get('write_mode', 'file')
        );

        if ($uploaded === false) {
            $message = __(
                __('There was an error while trying to upload the file %1$s to the target directory %2$s.'),
                [
                    '<code>' . $data['name'] . '</code>',
                    '<code>workspace/' . ltrim($rel_path, '/') . '</code>'
                ]
            );
            $status = self::__ERROR_CUSTOM__;

            return false;
        }

        // File has been replaced:
        if (
            isset($existing_file)
            && $existing_file !== $file
            && is_file($existing_file)
        ) {
            General::deleteFile($existing_file);
        }

        // Get the mimetype, don't trust the browser. RE: #1609
        $data['type'] = General::getMimeType($file);

        return [
            'file' =>       basename($file),
            'size' =>       $data['size'],
            'mimetype' =>   $data['type'],
            'meta' =>       serialize(static::getMetaInfo($file, $data['type']))
        ];
    }

    protected function getCurrentValues($entry_id)
    {
        return Symphony::Database()
            ->select(['file', 'mimetype', 'size', 'meta'])
            ->from('tbl_entries_data_' . $this->get('id'))
            ->where(['entry_id' => $entry_id])
            ->limit(1)
            ->execute()
            ->next();
    }

    /*-------------------------------------------------------------------------
        Output:
    -------------------------------------------------------------------------*/

    public function appendFormattedElement(
        XMLElement &$wrapper,
        array $data,
        bool $encode = false,
        string $mode = null,
        int $entry_id = null
    ): void
    {
        // It is possible an array of null data will be passed in. Check for this.
        if (!is_array($data) || !isset($data['file']) || is_null($data['file'])) {
            return;
        }

        $file = $this->getFilePath($data['file']);
        $filesize = (file_exists($file) && is_readable($file)) ? filesize($file) : null;
        $item = new XMLElement($this->get('element_name'));
        $item->setAttributeArray(array(
            'size' =>   !is_null($filesize) ? General::formatFilesize($filesize) : 'unknown',
            'bytes' =>  !is_null($filesize) ? $filesize : 'unknown',
            'path' =>   General::sanitize(
                str_replace(WORKSPACE, null, dirname($file))
            ),
            'type' =>   $data['mimetype']
        ));

        $item->appendChild(new XMLElement('filename', General::sanitize(basename($file))));

        $m = unserialize($data['meta']);

        if (is_array($m) && !empty($m)) {
            $item->appendChild(new XMLElement('meta', null, $m));
        }

        $wrapper->appendChild($item);
    }

    public function prepareTableValue(
        $data,
        XMLElement $link = null,
        int $entry_id = null
    ): string
    {
        if (isset($data['file']) === false || !$file = $data['file']) {
            return parent::prepareTableValue(null, $link, $entry_id);
        }

        if ($link) {
            $link->setValue(basename($file));
            $link->setAttribute('data-path', $this->get('destination'));

            return $link->generate();
        } else {
            $link = Widget::Anchor(basename($file), URL . $this->get('destination') . '/' . $file);
            $link->setAttribute('data-path', $this->get('destination'));

            return $link->generate();
        }
    }

    public function prepareTextValue(
        $data,
        int $entry_id = null
    ): ?string
    {
        if (isset($data['file'])) {
            return $data['file'];
        }
        return null;
    }

    public function prepareAssociationsDrawerXMLElement(
        Entry $e,
        array $parent_association,
        string $prepopulate = ''
    ): XMLElement
    {
        $li = parent::prepareAssociationsDrawerXMLElement($e, $parent_association);
        $a = $li->getChild(0);
        $a->setAttribute('data-path', $this->get('destination'));

        return $li;
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
            'getFilename' =>    ExportableField::VALUE,
            'getObject' =>      ExportableField::OBJECT,
            'getPostdata' =>    ExportableField::POSTDATA
        ];
    }

    /**
     * Give the field some data and ask it to return a value using one of many
     * possible modes.
     *
     * @param mixed $data
     * @param integer $mode
     * @param integer $entry_id
     * @return array|string|null
     */
    public function prepareExportValue(
        $data,
        int $mode,
        int $entry_id = null
    ): array|string|null
    {
        $modes = (object)$this->getExportModes();

        $filepath = $this->getFilePath($data['file']);

        // No file, or the file that the entry is meant to have no
        // longer exists.
        if (!isset($data['file']) || !is_file($filepath)) {
            return null;
        }

        if ($mode === $modes->getFilename) {
            return $data['file'];
        }

        if ($mode === $modes->getObject) {
            $object = (object)$data;

            if (isset($object->meta)) {
                $object->meta = unserialize($object->meta);
            }

            return $object;
        }

        if ($mode === $modes->getPostdata) {
            return $data['file'];
        }
    }

    /*-------------------------------------------------------------------------
        Filtering:
    -------------------------------------------------------------------------*/

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

        if (preg_match('/^mimetype:/', $data[0])) {
            $data[0] = str_replace('mimetype:', '', $data[0]);
            $column = 'mimetype';
        } elseif (preg_match('/^size:/', $data[0])) {
            $data[0] = str_replace('size:', '', $data[0]);
            $column = 'size';
        } else {
            $column = 'file';
        }

        if (self::isFilterRegex($data[0])) {
            $this->buildRegexSQL($data[0], [$column], $joins, $where);
        } elseif (self::isFilterSQL($data[0])) {
            $this->buildFilterSQL($data[0], [$column], $joins, $where);
        } elseif ($andOperation) {
            foreach ($data as $value) {
                $this->_key++;
                $value = $this->cleanValue($value);
                $joins .= "
                    LEFT JOIN
                        `tbl_entries_data_{$field_id}` AS t{$field_id}_{$this->_key}
                        ON (e.id = t{$field_id}_{$this->_key}.entry_id)
                ";
                $where .= "
                    AND t{$field_id}_{$this->_key}.{$column} = '{$value}'
                ";
            }
        } else {
            if (!is_array($data)) {
                $data = [$data];
            }

            foreach ($data as &$value) {
                $value = $this->cleanValue($value);
            }

            $this->_key++;
            $data = implode("', '", $data);
            $joins .= "
                LEFT JOIN
                    `tbl_entries_data_{$field_id}` AS t{$field_id}_{$this->_key}
                    ON (e.id = t{$field_id}_{$this->_key}.entry_id)
            ";
            $where .= "
                AND t{$field_id}_{$this->_key}.{$column} IN ('{$data}')
            ";
        }

        return true;
    }

    /*-------------------------------------------------------------------------
        Sorting:
    -------------------------------------------------------------------------*/

    /**
     * @deprecated @since Symphony 3.0.0
     * @see Field::buildSortingSQL()
     */
    public function buildSortingSQL(
        string &$joins,
        string &$where,
        string &$sort,
        string $order = 'ASC'
    ): void
    {
        if (Symphony::Log()) {
            Symphony::Log()->pushDeprecateWarningToLog(
                get_called_class() . '::buildSortingSQL()',
                'EntryQueryFieldAdapter::sort()'
            );
        }
        if ($this->isRandomOrder($order)) {
            $sort = 'ORDER BY RAND()';
        } else {
            $sort = sprintf(
                'ORDER BY (
                    SELECT %s
                    FROM tbl_entries_data_%d AS `ed`
                    WHERE entry_id = e.id
                ) %s, `e`.`id` %s',
                '`ed`.file',
                $this->get('id'),
                $order,
                $order
            );
        }
    }

    /**
     * @deprecated @since Symphony 3.0.0
     * @see Field::buildSortingSelectSQL()
     */
    public function buildSortingSelectSQL(
        string $sort,
        string $order = 'ASC'
    ): ?string
    {
        if (Symphony::Log()) {
            Symphony::Log()->pushDeprecateWarningToLog(
                get_called_class() . '::buildSortingSelectSQL()',
                'EntryQueryFieldAdapter::sort()'
            );
        }
        return null;
    }

    /*-------------------------------------------------------------------------
        Events:
    -------------------------------------------------------------------------*/

    public function getExampleFormMarkup(): XMLElement
    {
        $label = Widget::Label($this->get('label'));
        $label->appendChild(Widget::Input('fields['.$this->get('element_name').']', null, 'file'));

        return $label;
    }
}

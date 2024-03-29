<?php

/**
 * @package Email
 */

namespace Symnext\Email;

/**
 * A base class for email gateways.
 * All email-gateways should extend this class in order to work.
 *
 * @todo add validation to all set functions.
 */
abstract class EmailGateway
{
    protected $_recipients = [];
    protected $_sender_name;
    protected $_sender_email_address;
    protected $_subject;
    protected $_body;
    protected $_text_plain;
    protected $_text_html;
    protected $_attachments = [];
    protected $_validate_attachment_errors = true;
    protected $_reply_to_name;
    protected $_reply_to_email_address;
    protected $_header_fields = [];
    protected $_boundary_mixed;
    protected $_boundary_alter;
    protected $_text_encoding = 'quoted-printable';

    /**
     * Indicates whether the connection to the SMTP server should be
     * kept alive, or closed after sending each email. Defaults to false.
     *
     * @since Symphony 2.3.1
     * @var boolean
     */
    protected $_keepalive = false;

    /**
     * The constructor sets the `_boundary_mixed` and `_boundary_alter` variables
     * to be unique hashes based off PHP's `uniqid` function.
     */
    public function __construct()
    {
        $this->_boundary_mixed = '=_mix_'.md5(uniqid());
        $this->_boundary_alter = '=_alt_'.md5(uniqid());
    }

    /**
     * The destructor ensures that any open connections to the Email Gateway
     * is closed.
     */
    public function __destruct()
    {
        $this->closeConnection();
    }

    /**
     * Sends the actual email. This function should be implemented in the
     * Email Gateway itself and should return true or false if the email
     * was successfully sent.
     * See the default gateway for an example.
     *
     * @return boolean
     */
    abstract public function send(): bool;

    /**
     * Open new connection to the email server.
     * This function is used to allow persistent connections.
     *
     * @since Symphony 2.3.1
     * @return boolean
     */
    public function openConnection(): bool
    {
        $this->_keepalive = true;
        return true;
    }

    /**
     * Close the connection to the email Server.
     * This function is used to allow persistent connections.
     *
     * @since Symphony 2.3.1
     * @return boolean
     */
//     public function closeConnection(): void
    {
        $this->_keepalive = false;
        return true;
    }

    /**
     * Sets the sender-email and sender-name.
     *
     * @param string $email
     *  The email-address emails will be sent from.
     * @param string $name
     *  The name the emails will be sent from.
     * @throws EmailValidationException
     * @return void
     */
    public function setFrom(string $email, string $name): void
    {
        $this->setSenderEmailAddress($email);
        $this->setSenderName($name);
    }

    /**
     * Does some basic checks to validate the
     * value of a header field. Currently only checks
     * if the value contains a carriage return or a new line.
     *
     * @param string $value
     *
     * @return boolean
     */
    protected function validateHeaderFieldValue(string $value): bool
    {
        // values can't contains carriage returns or new lines
        $carriage_returns = preg_match('%[\r\n]%', $value);

        return !$carriage_returns;
    }

    /**
     * Sets the sender-email.
     *
     * @throws EmailValidationException
     * @param string $email
     *  The email-address emails will be sent from.
     * @return void
     */
    public function setSenderEmailAddress(string $email): void
    {
        if (!$this->validateHeaderFieldValue($email)) {
            throw new EmailValidationException(__('Sender Email Address can not contain carriage return or newlines.'));
        }

        $this->_sender_email_address = $email;
    }

    /**
     * Sets the sender-name.
     *
     * @throws EmailValidationException
     * @param string $name
     *  The name emails will be sent from.
     * @return void
     */
    public function setSenderName(string $name): void
    {
        if (!$this->validateHeaderFieldValue($name)) {
            throw new EmailValidationException(__('Sender Name can not contain carriage return or newlines.'));
        }

        $this->_sender_name = $name;
    }

    /**
     * Sets the recipients.
     *
     * @param string|array $email
     *  The email-address(es) to send the email to.
     * @throws EmailValidationException
     * @return void
     */
    public function setRecipients(string|array $email): void
    {
        if (!is_array($email)) {
            $email = explode(',', $email);
            // trim all values
            array_walk($email, function(&$val) {
                return $val = trim($val);
            });
            // remove empty elements
            $email = array_filter($email);
        }

        foreach ($email as $e) {
            if (!$this->validateHeaderFieldValue($e)) {
                throw new EmailValidationException(__('Recipient address can not contain carriage return or newlines.'));
            }
        }

        $this->_recipients = $email;
    }

    /**
     * This functions takes a string to be used as the plaintext
     * content for the Email
     *
     * @todo sanitizing and security checking
     * @param string $text_plain
     */
//     public function setTextPlain(string $text_plain): void
    {
        $this->_text_plain = $text_plain;
    }

    /**
     * This functions takes a string to be used as the HTML
     * content for the Email
     *
     * @todo sanitizing and security checking
     * @param string $text_html
     */
    public function setTextHtml(string $text_html): void
    {
        $this->_text_html = $text_html;
    }

    /**
     * This function sets one or multiple attachment files
     * to the email. It deletes all previously attached files.
     *
     * Passing `null` to this function will
     * erase the current values with an empty array.
     *
     * @param string|array $files
     *   Accepts the same parameters format as `EmailGateway::addAttachment()`
     *   but you can also all multiple values at once if all files are
     *   wrap in a array.
     *
     *   Example:
     *   ````
     *   $email->setAttachments([
     *      [
     *          'file' => 'http://example.com/foo.txt',
     *          'charset' => 'UTF-8'
     *      ],
     *      'path/to/your/webspace/foo/bar.csv',
     *      ...
     *   ]);
     *   ````
     */
    public function setAttachments(string|array $files): void
    {
        // Always erase
        $this->_attachments = [];

        // check if we have an input value
        if ($files == null) {
            return;
        }

        // make sure we are dealing with an array
        if (!is_array($files)) {
            $files = [$files];
        }

        // Append each attachment one by one in order
        // to normalize each input
        foreach ($files as $key => $file) {
            if (is_numeric($key)) {
                // key is numeric, assume keyed array or string
                $this->appendAttachment($file);
            } else {
                // key is not numeric, assume key is filename
                // and file is a string, key needs to be preserved
                $this->appendAttachment(array($key => $file));
            }
        }
    }

    /**
     * Appends one file attachment to the attachments array.
     *
     * @since Symphony 3.0.0
     *   The file array can contain a 'cid' key.
     *   When set to true, the Content-ID header field is added with the filename as id.
     *   The file array can contain a 'disposition' key.
     *   When set, it is used in the Content-Disposition header
     * @throws EmailGatewayException if the parameter is not valid.
     *
     * @since Symphony 2.3.5
     *
     * @param string|array $file
     *   Can be a string representing the file path, absolute or relative, i.e.
     *   `'http://example.com/foo.txt'` or `'path/to/your/webspace/foo/bar.csv'`.
     *
     *   Can also be a keyed array. This will enable more options, like setting the
     *   charset used by mail agent to open the file or a different filename.
     *   Only the "file" key is required.
     *
     *   Example:
     *   ````
     *   $email->appendAttachment(array(
     *      'file' => 'http://example.com/foo.txt',
     *      'filename' => 'bar.txt',
     *      'charset' => 'UTF-8',
     *      'mime-type' => 'text/csv',
     *      'cid' => false,
     *      'disposition' => 'inline',
     *   ));
     *   ````
     */
    public function appendAttachment(string|array $file): void
    {
        if (!is_array($file)) {
            // treat the param as string
            $file = [
                'file' => $file,
            ];

            // is array, but not the right key
        } elseif (!isset($file['file'])) {
            throw new EmailGatewayException('The file key is missing from the attachment array.');
        }

        // push properly formatted file entry
        $this->_attachments[] = $file;
    }

    /**
     * Sets the property `$_validate_attachment_errors`
     *
     * This property is true by default, so sending will break if any attachment
     * can not be loaded; if it is false, attachment errors error will be ignored.
     *
     * @since Symphony 2.7
     * @param boolean $validate_attachment_errors
     * @return void
     */
    public function setValidateAttachmentErrors(
        bool $validate_attachment_errors
    ): void
    {
        if (!is_bool($validate_attachment_errors)) {
            throw new EmailGatewayException(__('%s accepts boolean values only.', ['<code>setValidateAttachmentErrors</code>']));
        } else {
            $this->_validate_attachment_errors = $validate_attachment_errors;
        }
    }

    /**
     * @todo Document this function
     * @throws EmailGatewayException
     * @param string $encoding
     *  Must be either `quoted-printable` or `base64`.
     */
    public function setTextEncoding(string $encoding = null): void
    {
        if ($encoding == 'quoted-printable') {
            $this->_text_encoding = 'quoted-printable';
        } elseif ($encoding == 'base64') {
            $this->_text_encoding = 'base64';
        } elseif (!$encoding) {
            $this->_text_encoding = false;
        } else {
            throw new EmailGatewayException(__('%1$s is not a supported encoding type. Please use %2$s or %3$s. You can also use %4$s for no encoding.', [$encoding, '<code>quoted-printable</code>', '<code>base-64</code>', '<code>false</code>']));
        }
    }

    /**
     * Sets the subject.
     *
     * @param string $subject
     *  The subject that the email will have.
     * @return void
     */
    public function setSubject(string $subject): void
    {
        //TODO: sanitizing and security checking;
        $this->_subject = $subject;
    }

    /**
     * Sets the reply-to-email.
     *
     * @throws EmailValidationException
     * @param string $email
     *  The email-address emails should be replied to.
     * @return void
     */
    public function setReplyToEmailAddress(string $email): void
    {
        if (preg_match('%[\r\n]%', $email)) {
            throw new EmailValidationException(__('Reply-To Email Address can not contain carriage return or newlines.'));
        }

        $this->_reply_to_email_address = $email;
    }

    /**
     * Sets the reply-to-name.
     *
     * @throws EmailValidationException
     * @param string $name
     *  The name emails should be replied to.
     * @return void
     */
    public function setReplyToName(string $name): void
    {
        if (preg_match('%[\r\n]%', $name)) {
            throw new EmailValidationException(__('Reply-To Name can not contain carriage return or newlines.'));
        }

        $this->_reply_to_name = $name;
    }

    /**
     * Sets all configuration entries from an array.
     * This enables extensions like the ENM to create email settings panes that work regardless of the email gateway.
     * Every gateway should extend this method to add their own settings.
     *
     * @throws EmailValidationException
     * @param array $config
     * @since Symphony 2.3.1
     *  All configuration entries stored in a single array. The array should have the format of the $_POST array created by the preferences HTML.
     * @return boolean
     */
    public function setConfiguration(array $config)
    {
        return true;
    }

    /**
     * Appends a single header field to the header fields array.
     * The header field should be presented as a name/body pair.
     *
     * @throws EmailGatewayException
     * @param string $name
     *  The header field name. Examples are From, Bcc, X-Sender and Reply-to.
     * @param string $body
     *  The header field body.
     * @return void
     */
    public function appendHeaderField(
        string $name,
        string $body
    ): void
    {
        if (is_array($body)) {
            throw new EmailGatewayException(__('%s accepts strings only; arrays are not allowed.', ['<code>appendHeaderField</code>']));
        }

        $this->_header_fields[$name] = $body;
    }

    /**
     * Appends one or more header fields to the header fields array.
     * Header fields should be presented as an array with name/body pairs.
     *
     * @param array $header_array
     *  The header fields. Examples are From, X-Sender and Reply-to.
     * @throws EmailGatewayException
     * @return void
     */
    public function appendHeaderFields(array $header_array = []): void
    {
        foreach ($header_array as $name => $body) {
            $this->appendHeaderField($name, $body);
        }
    }

    /**
     * Makes sure the Subject, Sender Email and Recipients values are
     * all set and are valid. The message body is checked in
     * `prepareMessageBody`
     *
     * @see prepareMessageBody()
     * @throws EmailValidationException
     * @return boolean
     */
    public function validate(): bool
    {
        if (strlen(trim($this->_subject)) <= 0) {
            throw new EmailValidationException(__('Email subject cannot be empty.'));
        } elseif (strlen(trim($this->_sender_email_address)) <= 0) {
            throw new EmailValidationException(__('Sender email address cannot be empty.'));
        } elseif (!filter_var($this->_sender_email_address, FILTER_VALIDATE_EMAIL)) {
            throw new EmailValidationException(__('Sender email address must be a valid email address.'));
        } else {
            foreach ($this->_recipients as $address) {
                if (strlen(trim($address)) <= 0) {
                    throw new EmailValidationException(__('Recipient email address cannot be empty.'));
                } elseif (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    throw new EmailValidationException(__('The email address ‘%s’ is invalid.', [$address]));
                }
            }
        }

        return true;
    }

    /**
     * Build the message body and the content-describing header fields
     *
     * The result of this building is an updated body variable in the
     * gateway itself.
     *
     * @throws EmailGatewayException
     * @throws Exception
     * @return boolean
     */
    protected function prepareMessageBody(): void
    {
        $attachments = $this->getSectionAttachments();
        if ($attachments) {
            $this->appendHeaderFields($this->contentInfoArray('multipart/mixed'));
            if (!empty($this->_text_plain) && !empty($this->_text_html)) {
                $this->_body = $this->boundaryDelimiterLine('multipart/mixed')
                            . $this->contentInfoString('multipart/alternative')
                            . $this->getSectionMultipartAlternative()
                            . $attachments
                ;
            } elseif (!empty($this->_text_plain)) {
                $this->_body = $this->boundaryDelimiterLine('multipart/mixed')
                            . $this->contentInfoString('text/plain')
                            . $this->getSectionTextPlain()
                            . $attachments
                ;
            } elseif (!empty($this->_text_html)) {
                $this->_body = $this->boundaryDelimiterLine('multipart/mixed')
                            . $this->contentInfoString('text/html')
                            . $this->getSectionTextHtml()
                            . $attachments
                ;
            } else {
                $this->_body = $attachments;
            }
            $this->_body .= $this->finalBoundaryDelimiterLine('multipart/mixed');
        } elseif (!empty($this->_text_plain) && !empty($this->_text_html)) {
            $this->appendHeaderFields($this->contentInfoArray('multipart/alternative'));
            $this->_body = $this->getSectionMultipartAlternative();
        } elseif (!empty($this->_text_plain)) {
            $this->appendHeaderFields($this->contentInfoArray('text/plain'));
            $this->_body = $this->getSectionTextPlain();
        } elseif (!empty($this->_text_html)) {
            $this->appendHeaderFields($this->contentInfoArray('text/html'));
            $this->_body = $this->getSectionTextHtml();
        } else {
            throw new EmailGatewayException(__('No attachments or body text was set. Can not send empty email.'));
        }
    }

    /**
     * Build multipart email section. Used by sendmail and smtp classes to
     * send multipart email.
     *
     * Will return a string containing the section. Can be used to send to
     * an email server directly.
     * @return string
     */
    protected function getSectionMultipartAlternative(): string
    {
        $output = $this->boundaryDelimiterLine('multipart/alternative')
            . $this->contentInfoString('text/plain')
            . $this->getSectionTextPlain()
            . $this->boundaryDelimiterLine('multipart/alternative')
            . $this->contentInfoString('text/html')
            . $this->getSectionTextHtml()
            . $this->finalBoundaryDelimiterLine('multipart/alternative')
        ;

        return $output;
    }

    /**
     * Builds the attachment section of a multipart email.
     *
     * Will return a string containing the section. Can be used to send to
     * an email server directly.
     *
     * @throws EmailGatewayException
     * @throws Exception
     * @return string
     */
    protected function getSectionAttachments(): string
    {
        $output = '';

        foreach ($this->_attachments as $key => $file) {
            $tmp_file = false;

            // If the attachment is a URL, download the file to a temporary location.
            // This prevents downloading the file twice - once for info, once for data.
            if (filter_var($file['file'], FILTER_VALIDATE_URL)) {
                $gateway = new Gateway();
                $gateway->init($file['file']);
                $gateway->setopt('TIMEOUT', 30);
                $file_content = $gateway->exec();

                $tmp_file = tempnam(TMP, 'attachment');
                General::writeFile($tmp_file, $file_content, Symphony::Configuration()->get('write_mode', 'file'));

                $original_filename = $file['file'];
                $file['file'] = $tmp_file;

                // Without this the temporary filename will be used. Ugly!
                if (is_null($file['filename'])) {
                    $file['filename'] = basename($original_filename);
                }
            } else {
                $file_content = file_get_contents($file['file']);
            }

            if ($file_content !== false && !empty($file_content)) {
                $output .= $this->boundaryDelimiterLine('multipart/mixed')
                    . $this->contentInfoString(
                        $file['mime-type'] ?? null,
                        $file['file'],
                        $file['filename'] ?? null,
                        $file['charset'] ?? null,
                        $file['cid'] ?? null,
                        $file['disposition'] ?? 'attachment'
                    )
                    . EmailHelper::base64ContentTransferEncode($file_content);
            } else {
                if ($this->_validate_attachment_errors) {
                    if (!$tmp_file === false) {
                        $filename = $original_filename;
                    } else {
                        $filename = $file['file'];
                    }

                    throw new EmailGatewayException(__('The content of the file `%s` could not be loaded.', [$filename]));
                }
            }

            if (!$tmp_file === false) {
                General::deleteFile($tmp_file);
            }
        }
        return $output;
    }

    /**
     * Builds the text section of a text/plain email.
     *
     * Will return a string containing the section. Can be used to send to
     * an email server directly.
     * @return string
     */
    protected function getSectionTextPlain(): string
    {
        if ($this->_text_encoding == 'quoted-printable') {
            return EmailHelper::qpContentTransferEncode($this->_text_plain)."\r\n";
        } elseif ($this->_text_encoding == 'base64') {
            // don't add CRLF if using base64 - spam filters don't
            // like this
            return EmailHelper::base64ContentTransferEncode($this->_text_plain);
        }

        return $this->_text_plain."\r\n";
    }

    /**
     * Builds the html section of a text/html email.
     *
     * Will return a string containing the section. Can be used to send to
     * an email server directly.
     * @return string
     */
    protected function getSectionTextHtml(): string
    {
        if ($this->_text_encoding == 'quoted-printable') {
            return EmailHelper::qpContentTransferEncode($this->_text_html)."\r\n";
        } elseif ($this->_text_encoding == 'base64') {
            // don't add CRLF if using base64 - spam filters don't
            // like this
            return EmailHelper::base64ContentTransferEncode($this->_text_html);
        }
        return $this->_text_html."\r\n";
    }

    /**
     * Builds the right content-type/encoding types based on file and
     * content-type.
     *
     * Will try to match a common description, based on the $type param.
     * If nothing is found, will return a base64 attached file disposition.
     *
     * Can be used to send to an email server directly.
     *
     * @param string $type optional mime-type
     * @param string $file optional the path of the attachment
     * @param string $filename optional the name of the attached file
     * @param string $charset optional the charset of the attached file
     * @param string|boolean $cid optional add a Content-ID header field. If true, uses the filename as the cid
     * @param string $disposition optional the value of the Content-Disposition header field
     *
     * @return array
     */
    public function contentInfoArray(
        string $type = null,
        string $file = null,
        string $filename = null,
        string $charset = null,
        string|bool $cid = false,
        string $disposition = 'attachment'
    ): array
    {
        // Common descriptions
        $description = [
            'multipart/mixed' => [
                'Content-Type' => 'multipart/mixed; boundary="'
                                  .$this->getBoundary('multipart/mixed').'"',
            ],
            'multipart/alternative' => [
                'Content-Type' => 'multipart/alternative; boundary="'
                                  .$this->getBoundary('multipart/alternative').'"',
            ],
            'text/plain' => [
                'Content-Type'              => 'text/plain; charset=UTF-8',
                'Content-Transfer-Encoding' => $this->_text_encoding ? $this->_text_encoding : '8bit',
            ],
            'text/html' => [
                'Content-Type'              => 'text/html; charset=UTF-8',
                'Content-Transfer-Encoding' => $this->_text_encoding ? $this->_text_encoding : '8bit',
            ],
        ];

        // Try common
        if (!empty($type) && !empty($description[$type])) {
            // return it if found
            return $description[$type];
        }

        // assure we have a file name
        $filename = !is_null($filename) ? $filename : basename($file);

        // Format charset for insertion in content-type, if needed
        if (!empty($charset)) {
            $charset = sprintf('charset=%s;', $charset);
        } else {
            $charset = '';
        }
        // if the mime type is not set, try to obtain using the getMimeType
        if (empty($type)) {
            //assume that the attachment mimetime is appended
            $type = General::getMimeType($file);
        }
        // Return binary description
        $bin = [
            'Content-Type'              => $type.';'.$charset.' name="'.$filename.'"',
            'Content-Transfer-Encoding' => 'base64',
        ];
        if ($disposition) {
            $bin['Content-Disposition'] = $disposition . '; filename="' .$filename .'"';
        }
        if ($cid) {
            $bin['Content-ID'] = $cid === true ? "<$filename>" : $cid;
        }
        return $bin;
    }

    /**
     * Creates the properly formatted InfoString based on the InfoArray.
     *
     * @see EmailGateway::contentInfoArray()
     *
     * @return string|null
     */
    protected function contentInfoString(
        string $type = null,
        string $file = null,
        string $filename = null,
        string $charset = null,
        bool $cid = false,
        string $disposition = 'attachment'
    ): string|null
    {
        $data = $this->contentInfoArray($type, $file, $filename, $charset, $cid, $disposition);
        $fields = [];

        foreach ($data as $key => $value) {
            $fields[] = EmailHelper::fold(sprintf('%s: %s', $key, $value));
        }

        return !empty($fields) ? implode("\r\n", $fields)."\r\n\r\n" : null;
    }

    /**
     * Returns the bondary based on the $type parameter
     *
     * @param string $type the multipart type
     * @return string|void
     */
    protected function getBoundary(string $type): string|void
    {
        switch ($type) {
            case 'multipart/mixed':
                return $this->_boundary_mixed;
            case 'multipart/alternative':
                return $this->_boundary_alter;
        }
    }

    /**
     * @param string $type
     * @return string
     */
    protected function boundaryDelimiterLine(string $type): string
    {
        // As requested by RFC 2046: 'The CRLF preceding the boundary
        // delimiter line is conceptually attached to the boundary.'
        return $this->getBoundary($type) ? "\r\n--".$this->getBoundary($type)."\r\n" : null;
    }

    /**
     * @param string $type
     * @return string
     */
    protected function finalBoundaryDelimiterLine(string $type): string
    {
        return $this->getBoundary($type) ? "\r\n--".$this->getBoundary($type)."--\r\n" : null;
    }

    /**
     * Sets a property.
     *
     * Magic function, supplied by PHP.
     * This function will try and find a method of this class, by
     * camelcasing the name, and appending it with set.
     * If the function can not be found, an exception will be thrown.
     *
     * @param string $name
     *  The property name.
     * @param string $value
     *  The property value;
     * @throws EmailGatewayException
     * @return void|boolean
     */
    public function __set(string $name, string $value)
    {
        if (method_exists(get_class($this), 'set'.$this->__toCamel($name, true))) {
            return $this->{'set'.$this->__toCamel($name, true)}($value);
        } else {
            throw new EmailGatewayException(__('The %1$s gateway does not support the use of %2$s', [get_class($this), $name]));
        }
    }

    /**
     * Gets a property.
     *
     * Magic function, supplied by PHP.
     * This function will attempt to find a variable set with `$name` and
     * returns it. If the variable is not set, it will return false.
     *
     * @since Symphony 2.2.2
     * @param string $name
     *  The property name.
     * @return boolean|mixed
     */
    public function __get(string $name)
    {
        return isset($this->{'_'.$name}) ? $this->{'_'.$name} : false;
    }

    /**
     * The preferences to add to the preferences pane in the admin area.
     *
     * @return XMLElement
     */
    public function getPreferencesPane(): XMLElement
    {
        return new XMLElement('fieldset');
    }

    /**
     * Internal function to turn underscored variables into camelcase, for
     * use in methods.
     * Because Symphony has a difference in naming between properties and
     * methods (underscored vs camelcased) and the Email class uses the
     * magic __set function to find property-setting-methods, this
     * conversion is needed.
     *
     * @param string $string
     *  The string to convert
     * @param boolean $caseFirst
     *  If this is true, the first character will be uppercased. Useful
     *  for method names (setName).
     *  If set to false, the first character will be lowercased. This is
     *  default behaviour.
     * @return string
     */
    private function __toCamel(
        string $string,
        bool $caseFirst = false
    ): string
    {
        $string = strtolower($string);
        $a = explode('_', $string);
        $a = array_map('ucfirst', $a);

        if (!$caseFirst) {
            $a[0] = lcfirst($a[0]);
        }

        return implode('', $a);
    }

    /**
     * The reverse of the __toCamel function.
     *
     * @param string $string
     *  The string to convert
     * @return string
     */
    private function __fromCamel(string $string): string
    {
        $string[0] = strtolower($string[0]);

        return preg_replace_callback('/([A-Z])/', function($c) {
            return "_" . strtolower($c[1]);
        }, $string);
    }
}

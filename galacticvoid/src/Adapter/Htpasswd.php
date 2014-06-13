<?php
/**
 * 
 * This file is part of Aura for PHP.
 * 
 * @package Aura.Auth
 * 
 * @license http://opensource.org/licenses/bsd-license.php BSD
 * 
 */
namespace Aura\Auth\Adapter;

use Aura\Auth\Exception\FileNotReadable;
use Aura\Auth\Exception\MissingUsernameOrPassword;

/**
 * 
 * Authenticate 
 * 
 * @package Aura.Auth
 * 
 */
class Htpasswd extends FileAdapter
{
    public function authenticate()
    {
        if (! isset($this->username) || ! isset($this->password)) {
            throw new MissingUsernameOrPassword();
        }

        if (empty($this->username) || empty($this->password)) {
            return false;
        }

        // open the file
        $fp = fopen($this->file, 'r');

        if (! $fp) {
            throw new FileNotReadable();
        }

        // find the user's line in the file
        $len = strlen($this->username) + 1;
        $found  = false;

        while ($line = fgets($fp)) {
            if (substr($line, 0, $len) == "{$this->username}:") {
                // found the line, leave the loop
                $found = true;
                break;
            }
        }

        // close the file
        fclose($fp);

        // did we find the username?
        if (! $found) {
            // username not in the file
            return false;
        }

        // break up the pieces: 0 = username, 1 = encrypted (hashed)
        // password. may be more than that but we don't care.
        $tmp = explode(':', trim($line));
        $stored_hash = $tmp[1];

        // what kind of encryption hash are we using?  look at the first
        // few characters of the hash to find out.
        if (substr($stored_hash, 0, 6) == '$apr1$') {

            // use the apache-specific MD5 encryption
            $computed_hash = $this->hashApr1($this->password, $stored_hash);

        } elseif (substr($stored_hash, 0, 5) == '{SHA}') {

            // use SHA1 encryption.  pack SHA binary into hexadecimal,
            // then encode into characters using base64. this is per
            // Tomas V. V. Cox.
            $hex           = pack('H40', sha1($this->password));
            $computed_hash = '{SHA}' . base64_encode($hex);

        } else {

            // use DES encryption (the default).
            //
            // Note that crypt() will only check up to the first 8
            // characters of a password; chars after 8 are ignored. This
            // means that if the real password is "atecharsnine", the
            // word "atechars" would be valid.  This is bad.  As a
            // workaround, if the password provided by the user is
            // longer than 8 characters, this method will *not* validate
            // it.
            //
            // is the password longer than 8 characters?
            if (strlen($this->password) > 8) {
                // automatically reject
                return false;
            } else {
                $computed_hash = crypt($this->password, $stored_hash);
            }
        }

        // did the hashes match?
        if ($stored_hash == $computed_hash) {
            return true;
        }
        return false;
    }

    /**
     * 
     * APR compatible MD5 encryption.
     * 
     * @author Mike Wallner <mike@php.net>
     * 
     * @author Paul M. Jones (minor modfications) <pmjones@solarphp.com>
     * 
     * @param string $plain Plaintext to crypt.
     * 
     * @param string $salt The salt to use for encryption.
     * 
     * @return string The APR MD5 encrypted string.
     * 
     */
    protected function hashApr1($plain, $salt)
    {
        $salt    = preg_replace('/^\$apr1\$([^$]+)\$.*/', '\\1', $salt);
        $length  = strlen($plain);
        $context = $plain . '$apr1$' . $salt;
        $binary  = hash('md5', $plain . $salt . $plain, true);

        for ($i = $length; $i > 0; $i -= 16) {
            $context .= substr($binary, 0, min(16, $i));
        }

        for ($i = $length; $i > 0; $i >>= 1) {
            $context .= ($i & 1) ? chr(0) : $plain[0];
        }

        $binary = hash('md5', $context, true);

        for ($i = 0; $i < 1000; $i++) {
            $new = ($i & 1) ? $plain : $binary;

            if ($i % 3) {
                $new .= $salt;
            }

            if ($i % 7) {
                $new .= $plain;
            }

            $new   .= ($i & 1) ? $binary : $plain;
            $binary = hash('md5', $new, true);
        }

        $p = array();

        for ($i = 0; $i < 5; $i++) {
            $k = $i + 6;
            $j = $i + 12;

            if ($j == 16) {
                $j = 5;
            }

            $p[] = $this->chars64(
                (ord($binary[$i]) << 16) |
                (ord($binary[$k]) << 8)  |
                (ord($binary[$j])),
                5
            );
        }

        return '$apr1$' . $salt . '$' . implode($p)
             . $this->chars64(ord($binary[11]), 3);
    }

    /**
     * 
     * Convert to allowed 64 characters for encryption.
     * 
     * @author Mike Wallner <mike@php.net>
     * 
     * @author Paul M. Jones (minor modfications) <pmjones@solarphp.com>
     * 
     * @param string $value The value to convert.
     * 
     * @param int $count The number of characters.
     * 
     * @return string The converted value.
     * 
     */
    protected function chars64($value, $count)
    {
        $charset = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $result  = '';
        while (--$count) {
            $result .= $charset[$value & 0x3f];
            $value >>= 6;
        }
        return $result;
    }
}

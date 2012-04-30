<?php
/* FileStream.class.php - Class for abstracting PHP file streams as objects
 * Copyright (C) 2007 Erik Osterman <e@osterman.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/* File Authors:
 *   Erik Osterman <e@osterman.com>
 */

class FileStream implements Iterator {
  const SHARED      = LOCK_SH;
  const EXCLUSIVE   = LOCK_EX;
  const NONBLOCKING = LOCK_NB;
  
  private $path;
  private $fh;
  private $mode;
  private $iterator;

  public function __construct( $path = null, $mode = 'r' )
  {
    if( $path === null )
    {
      $path = tempnam("/tmp", "tmp.");
      $mode = 'w';
      touch($path); // Use PHP's touch
    }
    
    $this->mode = $mode;
    if( ! file_exists($path) || is_file($path) )
      $this->path = $path;
    else
      throw new Exception( get_class($this) . "::__construct path {$path} is not a file");
    $this->fh = fopen( $this->path, $this->mode );
    if( ! $this->fh )
      throw new Exception( get_class($this) . "::__construct fopen {$this->path} failed");
  }

  public function __destruct()
  {
    $this->unlock();
    fclose($this->fh);
    unset($this->path);
    unset($this->fh);
    unset($this->mode);
    unset($this->iterator);
  }

  public function __get( $property )
  {
    switch( $property )
    {
      case 'fh':
        return $this->fh;
      case 'feof':
        return feof($this->fh);
      case 'exists':
        return $this->exists();
      case 'offset':
        return ftell($this->fh);
      case 'atime':
        return fileatime($this->path);
      case 'ctime':
        return filectime($this->path);
      case 'group':
        return filegroup($this->path);
      case 'inode':
        return fileinode($this->path);
      case 'mtime':
        return filemtime($this->path);
      case 'owner':
        return fileowner($this->path);
      case 'perms':
        return substr( sprintf('%o', fileperms($this->path)), -4);
      case 'empty':
        return filesize($this->path) == 0;
      case 'size':
        return filesize($this->path);
      case 'type':
        return filetype($this->path);
      case 'filename':
      case 'extension':
        $info = pathinfo($this->path);
        return $info[$property];
      case 'dirname':
        return dirname($this->path);
      case 'basename':
        return basename($this->path);
      case 'delete':
        return $this->delete();
      case 'flush':
        return fflush($this->fh);
      case 'md5':
        return md5_file($this->path);
      case 'path':
        return $this->path;
      case 'realpath':
        return realpath($this->path);
      case 'executable':
        return is_executable($this->path);
      case 'writable':
        return is_writable($this->path);
      case 'readable':
        return is_readable($this->path);
      case 'mode':
        return $this->mode;
      default:
        throw new Exception( get_class($this) . "::$property not handled");
    }
  }

  public function __set($property, $value)
  {
    throw new Exception( get_class($this) . "::$property cannot be set");
  }

  public function __unset($property)
  {
    throw new Exception( get_class($this) . "::$property cannot be unset");
  }

  public function __toString()
  {
    return file_get_contents($this->path);
  }

  public function copy( $newPath )
  {
    $this->lock();
    copy( $this->path, $newPath );
    $this->unlock();
    return new FileStream( $newPath );
  }

  public function rename($newPath)
  {
    $this->lock();
    //if( file_exists($newPath) )
    //  throw new Exception( get_class($this) . "::rename failed. Cannot overwrite existing file {$newPath}");
    @rename($this->path, $newPath);
    if( file_exists($this->path) )
      throw new Exception( get_class($this) . "::rename failed. Source file still exists");
    if( !file_exists($newPath) )
      throw new Exception( get_class($this) . "::rename failed");
    $this->path = $newPath;

    $this->fh = fopen($this->path, 'r');
    $this->unlock();
  
  }

  public function unlink()
  {
    return unlink($this->path);
  }

  public function delete()
  {
    return $this->unlink();
  }

  public function seek($offset, $whence = SEEK_SET)
  {
    return fseek($this->fh, $offset, $whence);
  }

  public function lock( $operation = self::EXCLUSIVE)
  {
    if($operation & FileStream::NONBLOCKING)
    {
      $wouldBlock = null;
      $haveLock = flock($this->fh, $operation, $wouldBlock);
      return $haveLock && !$wouldBlock;
    } else {
      return flock($this->fh, $operation);
    }
  }

  public function isLocked()
  {
    if($this->lock(self::NONBLOCKING|self::EXCLUSIVE))
    {
      $this->unlock();
      return false;
    }
    else 
    {
      return true;
    }
  }

  public function unlock()
  {
    return flock($this->fh, LOCK_UN);
  }

  public function truncate( $size )
  {
    return ftruncate($this->fh, $size );
  }

  public function touch()
  {
    // Poor man's touch. PHP's built in touch doesn't let you modify the mtime of a file
    // not owned by the current user, even if you have write access. 
    $this->lock();
    $this->truncate( $this->size );
    $this->unlock();
  }

  public function chmod($mode)
  {
    if( strlen($mode) != 4 )
      throw new Exception( get_class($this) . "::chmod invalid mode '$mode'");
    return chmod($this->path, octdec("$mode"));
  }

  public function chgrp($gid)
  {
    return chgrp($this->path, $gid);
  }

  public function exists()
  {
    return file_exists($this->path);
  }

  public function read( $bytes = null )
  {
    if( $bytes )
      return fread($this->fh, $bytes);
    else
      return fgets($this->fh);
  }

  public function readCSV($delimeter = ',', $enclosure = '"', $length = 8012)
  {
    return fgetcsv($this->fh, $length, $delimeter, $enclosure);
  }

  public function write( $buf )
  {
    return fwrite($this->fh, $buf);
  }

  // Iterator Methods
  public function rewind()
  {
    return $this->next();
  }

  public function current()
  {
    return $this->iterator;
  }

  public function key()
  {
    throw new Exception( get_class($this) . "::key not implemented");
  }

  public function next()
  {
    $this->iterator = $this->read();
    return $this->iterator;
  }

  public function prev()
  {
    throw new Exception( get_class($this) . "::prev not implemented");
  }

  public function valid()
  {
    return !$this->feof;
  }
  
}


?>

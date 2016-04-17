<?php
#*************************************************************************
# This is a Python library for the Adafruit Thermal Printer.
# Pick one up at --> http://www.adafruit.com/products/597
# These printers use TTL serial to communicate, 2 pins are required.
# IMPORTANT: On 3.3V systems (e.g. Raspberry Pi), use a 10K resistor on
# the RX pin (TX on the printer, green wire), or simply leave unconnected.
#
# Adafruit invests time and resources providing this open source code.
# Please support Adafruit and open-source hardware by purchasing products
# from Adafruit!
#
# Written by Limor Fried/Ladyada for Adafruit Industries.
# Python port by Phil Burgess for Adafruit Industries.
# MIT license, all text above must be included in any redistribution.
#*************************************************************************

# This is pretty much a 1:1 direct Python port of the Adafruit_Thermal
# library for Arduino.  All methods use the same naming conventions as the
# Arduino library, with only slight changes in parameter behavior where
# needed.  This should simplify porting existing Adafruit_Thermal-based
# printer projects to Raspberry Pi, BeagleBone, etc.  See printertest.py
# for an example.
#
# One significant change is the addition of the printImage() function,
# which ties this to the Python Imaging Library and opens the door to a
# lot of cool graphical stuff!
#
# TO DO:
# - Might use standard ConfigParser library to put thermal calibration
#   settings in a global configuration file (rather than in the library).
# - Make this use proper Python library installation procedure.
# - Trap errors properly.  Some stuff just falls through right now.
# - Add docstrings throughout!

# Python 2.X code using the library usu. needs to include the next line:
//from __future__ import print_function
//from serial import Serial
//import time

namespace Printer;

use Symfony\Component\OptionsResolver\OptionsResolver;

class Printer
{
    protected $serial = null;

    protected $defaults = [
        'resumeTime'    => 0.0,
        'byteTime'      => 0.0,
        'dotPrintTime'  => 0.033,
        'dotFeedTime'   => 0.0025,
        'prevByte'      => '\n',
        'column'        => 0,
        'maxColumn'     => 32,
        'charHeight'    => 24,
        'lineSpacing'   => 8,
        'barcodeHeight' => 50,
        'printMode'     => 0,
        'heatTime'      => 60,
        'baudrate'      => 19200,
        'device'        => '/dev/ttyAMA0',
    ];

    protected $resumeTime;
    protected $byteTime;
    protected $dotPrintTime;
    protected $dotFeedTime;
    protected $prevByte;
    protected $column;
    protected $maxColumn;
    protected $charHeight;
    protected $lineSpacing;
    protected $barcodeHeight;
    protected $printMode;
    protected $heatTime;
    protected $baudrate;
    protected $device;

    # === Character commands ===

    protected $INVERSE_MASK;
    protected $UPDOWN_MASK;
    protected $BOLD_MASK;
    protected $DOUBLE_HEIGHT_MASK;
    protected $DOUBLE_WIDTH_MASK;
    protected $STRIKE_MASK;

    public function __construct($config = [])
    {
        $optionsResolver = new OptionsResolver();
        $optionsResolver->setDefaults($this->defaults);
        $config = $optionsResolver->resolve($config);

        $this->resumeTime    = $config['resumeTime'];
        $this->byteTime      = $config['byteTime'];
        $this->dotPrintTime  = $config['dotPrintTime'];
        $this->dotFeedTime   = $config['dotFeedTime'];
        $this->prevByte      = $config['prevByte'];
        $this->column        = $config['column'];
        $this->maxColumn     = $config['maxColumn'];
        $this->charHeight    = $config['charHeight'];
        $this->lineSpacing   = $config['lineSpacing'];
        $this->barcodeHeight = $config['barcodeHeight'];
        $this->printMode     = $config['printMode'];
        $this->heatTime      = $config['heatTime'];
        $this->baudrate      = $config['baudrate'];
        $this->device        = $config['device'];

        # Calculate time to issue one byte to the printer.
        # 11 bits (not 8) to accommodate idle, start and stop bits.
        # Idle time might be unnecessary, but erring on side of
        # caution here.
        $this->byteTime = 11.0 / (float) $this->baudrate;

        $this->serial = new \PhpSerial();

        $this->serial->deviceSet($this->device);

        # We can change the baud rate, parity, length, stop bits, flow control
        $this->serial->confBaudRate($this->baudrate);
        //$this->serial->confParity("none");
        //$this->serial->confCharacterLength(8);
        //$this->serial->confStopBits(1);
        //$this->serial->confFlowControl("none");

        # Then we need to open it
        $this->serial->deviceOpen();

        # The printer can't start receiving data immediately upon
        # power up -- it needs a moment to cold boot and initialize.
        # Allow at least 1/2 sec of uptime before printer can
        # receive data.
        $this->timeoutSet(1);

        $this->wake();
        $this->reset();

        # Description of print settings from page 23 of the manual:
        # ESC 7 n1 n2 n3 Setting Control Parameter Command
        # Decimal: 27 55 n1 n2 n3
        # Set "max heating dots", "heating time", "heating interval"
        # n1 = 0-255 Max heat dots, Unit (8dots), Default: 7 (64 dots)
        # n2 = 3-255 Heating time, Unit (10us), Default: 80 (800us)
        # n3 = 0-255 Heating interval, Unit (10us), Default: 2 (20us)
        # The more max heating dots, the more peak current will cost
        # when printing, the faster printing speed. The max heating
        # dots is 8*(n1+1).  The more heating time, the more density,
        # but the slower printing speed.  If heating time is too short,
        # blank page may occur.  The more heating interval, the more
        # clear, but the slower printing speed.

        $heatTime = isset($config['heattime']) ? $config['heattime'] : $this->heatTime;
        $this->writeBytes(
          27,        # Esc
          55,        # 7 (print settings)
          32,        # Heat dots (20 = balance darkness w/no jams)
          55,        # Lib default = 45
          250        # Heat interval (500 uS = slower but darker)
        );

        # Description of print density from page 23 of the manual:
        # DC2 # n Set printing density
        # Decimal: 18 35 n
        # D4..D0 of n is used to set the printing density.
        # Density is 50% + 5% * n(D4-D0) printing density.
        # D7..D5 of n is used to set the printing break time.
        # Break time is n(D7-D5)*250us.
        # (Unsure of the default value for either -- not documented)

        $printDensity   = 14; # 120% (can go higher, but text gets fuzzy)
        $printBreakTime =  4; # 500 uS

        $this->writeBytes(
          18, # DC2
          35, # Print density
          ($printBreakTime << 5) | $printDensity
        );

        $this->dotPrintTime = 0.03;
        $this->dotFeedTime  = 0.0021;

        $this->INVERSE_MASK       = (1 << 1);
        $this->UPDOWN_MASK        = (1 << 2);
        $this->BOLD_MASK          = (1 << 3);
        $this->DOUBLE_HEIGHT_MASK = (1 << 4);
        $this->DOUBLE_WIDTH_MASK  = (1 << 5);
        $this->STRIKE_MASK        = (1 << 6);
    }

    # Because there's no flow control between the printer and computer,
    # special care must be taken to avoid overrunning the printer's
    # buffer.  Serial output is throttled based on serial speed as well
    # as an estimate of the device's print and feed rates (relatively
    # slow, being bound to moving parts and physical reality).  After
    # an operation is issued to the printer (e.g. bitmap print), a
    # timeout is set before which any other printer operations will be
    # suspended.  This is generally more efficient than using a delay
    # in that it allows the calling code to continue with other duties
    # (e.g. receiving or decoding an image) while the printer
    # physically completes the task.

    # Sets estimated completion time for a just-issued task.
    public function timeoutSet($x)
    {
        $this->resumeTime = microtime(true) + $x;
    }

    # Waits (if necessary) for the prior task to complete.
    public function timeoutWait()
    {
        while ((microtime(true) - $this->resumeTime) < 0) continue;
    }

    # Printer performance may vary based on the power supply voltage,
    # thickness of paper, phase of the moon and other seemingly random
    # variables.  This method sets the times (in microseconds) for the
    # paper to advance one vertical 'dot' when printing and feeding.
    # For example, in the default initialized state, normal-sized text
    # is 24 dots tall and the line spacing is 32 dots, so the time for
    # one line to be issued is approximately 24 * print time + 8 * feed
    # time.  The default print and feed times are based on a random
    # test unit, but as stated above your reality may be influenced by
    # many factors.  This lets you tweak the timing to avoid excessive
    # delays and/or overrunning the printer buffer.
    public function setTimes($p, $f)
    {
        # Units are in microseconds for
        # compatibility with Arduino library
        $this->dotPrintTime = $p / 1000000.0;
        $this->dotFeedTime  = $f / 1000000.0;
    }

    # 'Raw' byte-writing method
    public function writeBytes()
    {
        $args = func_get_args();
        $this->timeoutWait();
        $this->timeoutSet(sizeof($args) * $this->byteTime);

        foreach ($args as $arg) {
            $this->serial->sendMessage(chr($arg));
        }
    }

    # Override write() method to keep track of paper feed.
    public function write()
    {
        $data = func_get_args();
        for ($i = 0; $i <= sizeof($data); $i++) {
            var_dump($data);
            $c = $data[$i];
            if ($c != 0x13) {
                $this->timeoutWait();

                $this->serial->sendMessage($c);

                $d = $this->byteTime;
                if (($c == '\n') or ($this->column == $this->maxColumn)) {
                    # Newline or wrap
                    if ($this->prevByte == '\n') {
                        # Feed line (blank)
                        $d += (($this->charHeight +
                               $this->lineSpacing) *
                              $this->dotFeedTime);
                    } else {
                        # Text line
                        $d += (($this->charHeight *
                               $this->dotPrintTime) +
                              ($this->lineSpacing *
                               $this->dotFeedTime));
                        $this->column = 0;
                        # Treat wrap as newline
                        # on next pass
                        $c = '\n';
                    }
                } else {
                    $this->column += 1;
                }

                $this->timeoutSet($d);
                $this->prevByte = $c;
            }
        }
    }

    # The bulk of this method was moved into __init__,
    # but this is left here for compatibility with older
    # code that might get ported directly from Arduino.
    public function begin($heatTime = null)
    {
        $heatTime = $heatTime ?: $this->heatTime;
        $this->writeBytes(
          27,        # Esc
          55,        # 7 (print settings)
          20,        # Heat dots (20 = balance darkness w/no jams)
          $heatTime, # Lib default = 45
          250);      # Heat interval (500 uS = slower but darker)
    }

    public function reset()
    {
        $this->prevByte      = '\n'; # Treat as if prior line is blank
        $this->column        =  0;
        $this->maxColumn     = 32;
        $this->charHeight    = 24;
        $this->lineSpacing   =  8;
        $this->barcodeHeight = 50;
        $this->writeBytes(27, 64);
    }

    # Reset text formatting parameters.
    public function setDefault()
    {
        $this->online();
        $this->justify('L');
        $this->inverseOff();
        $this->doubleHeightOff();
        $this->setLineHeight(32);
        $this->boldOff();
        $this->underlineOff();
        $this->setBarcodeHeight(50);
        $this->setSize('s');
    }

    public function test()
    {
        $this->writeBytes(18, 84);
        $this->timeoutSet(
          $this->dotPrintTime * 24 * 26 +
          $this->dotFeedTime  * (8 * 26 + 32)
        );
    }

    /*
    UPC_A   =  0
    UPC_E   =  1
    EAN13   =  2
    EAN8    =  3
    CODE39  =  4
    I25     =  5
    CODEBAR =  6
    CODE93  =  7
    CODE128 =  8
    CODE11  =  9
    MSI     = 10
    */

    public function printBarcode($text, $type)
    {
        $this->writeBytes(
          29,  72, 2,      # Print label below barcode
          29, 119, 3,      # Barcode width
          29, 107, $type); # Barcode type
        # Print string
        $this->timeoutWait();
        $this->timeoutSet(($this->barcodeHeight + 40) * $this->dotPrintTime);

        $this->serial->sendMessage($text);

        $this->prevByte = '\n';
        $this->feed(2);
    }

    public function setBarcodeHeight($val = 50)
    {
        if ($val < 1) $val = 1;
        $this->barcodeHeight = $val;
        $this->writeBytes(29, 104, $val);
    }

    public function setPrintMode($mask)
    {
        $this->printMode |= $mask;
        $this->writePrintMode();

        if ($this->printMode & $this->DOUBLE_HEIGHT_MASK):
            $this->charHeight = 48;
        else:
            $this->charHeight = 24;
        endif;

        if ($this->printMode & $this->DOUBLE_WIDTH_MASK):
            $this->maxColumn  = 16;
        else:
            $this->maxColumn  = 32;
        endif;
    }

    public function unsetPrintMode($mask)
    {
        $this->printMode &= ~$mask;
        $this->writePrintMode();

        if ($this->printMode & $this->DOUBLE_HEIGHT_MASK):
            $this->charHeight = 48;
        else:
            $this->charHeight = 24;
        endif;

        if ($this->printMode & $this->DOUBLE_WIDTH_MASK):
            $this->maxColumn  = 16;
        else:
            $this->maxColumn  = 32;
        endif;
    }

    public function writePrintMode()
    {
        $this->writeBytes(27, 33, $this->printMode);
    }

    public function normal()
    {
        $this->printMode = 0;
        $this->writePrintMode();
    }

    public function inverseOn()
    {
        $this->setPrintMode($this->INVERSE_MASK);
    }

    public function inverseOff()
    {
        $this->unsetPrintMode($this->INVERSE_MASK);
    }

    public function upsideDownOn()
    {
        $this->setPrintMode($this->UPDOWN_MASK);
    }

    public function upsideDownOff()
    {
        $this->unsetPrintMode($this->UPDOWN_MASK);
    }

    public function doubleHeightOn()
    {
        $this->setPrintMode($this->DOUBLE_HEIGHT_MASK);
    }

    public function doubleHeightOff()
    {
        $this->unsetPrintMode($this->DOUBLE_HEIGHT_MASK);
    }

    public function doubleWidthOn()
    {
        $this->setPrintMode($this->DOUBLE_WIDTH_MASK);
    }

    public function doubleWidthOff()
    {
        $this->unsetPrintMode($this->DOUBLE_WIDTH_MASK);
    }

    public function strikeOn()
    {
        $this->setPrintMode($this->STRIKE_MASK);
    }

    public function strikeOff()
    {
        $this->unsetPrintMode($this->STRIKE_MASK);
    }

    public function boldOn()
    {
        $this->setPrintMode($this->BOLD_MASK);
    }

    public function boldOff()
    {
        $this->unsetPrintMode($this->BOLD_MASK);
    }

    public function justify($value)
    {
        $c = strtoupper($value);

        if ($c == 'C'):
            $pos = 1;
        elseif ($c == 'R'):
            $pos = 2;
        else:
            $pos = 0;
        endif;

        $this->writeBytes(0x1B, 0x61, $pos);
    }

    # Feeds by the specified number of lines
    public function feed($x = 1)
    {
        # The datasheet claims sending bytes 27, 100, <x> will work,
        # but it feeds much more than that.  So it's done manually:
        while ($x > 0):
            $this->write('\n');
            $x -= 1;
        endwhile;
    }

    # Feeds by the specified number of individual pixel rows
    public function feedRows($rows)
    {
        $this->writeBytes(27, 74, $rows);
        $this->timeoutSet($rows * $this->dotFeedTime);
    }

    public function flush()
    {
        $this->writeBytes(12);
    }

    public function setSize($value)
    {
        $c = strtoupper($value);

        if ($c == 'L'):   # Large: double width and height
            $size             = 0x11;
            $this->charHeight = 48;
            $this->maxColumn  = 16;
        elseif ($c == 'M'): # Medium: double height
            $size             = 0x01;
            $this->charHeight = 48;
            $this->maxColumn  = 32;
        else:          # Small: standard width and height
            $size             = 0x00;
            $this->charHeight = 24;
            $this->maxColumn  = 32;
        endif;

        $this->writeBytes(29, 33, $size, 10);
        $this->prevByte = '\n'; # Setting the size adds a linefeed;
    }

    # Underlines of different weights can be produced:
    # 0 - no underline
    # 1 - normal underline
    # 2 - thick underline
    public function underlineOn($weight = 1)
    {
        $this->writeBytes(27, 45, $weight);
    }

    public function underlineOff()
    {
        $this->underlineOn(0);
    }

    public function printBitmap($w, $h, $bitmap, $LaaT = false)
    {
        $rowBytes = (int) (($w + 7) / 8);  # Round up to next byte boundary
        if ($rowBytes >= 48) {
            $rowBytesClipped = 48;  # 384 pixels max width
        } else {
            $rowBytesClipped = $rowBytes;
        }

        # if LaaT (line-at-a-time) is True, print bitmaps
        # scanline-at-a-time (rather than in chunks).
        # This tends to make for much cleaner printing
        # (no feed gaps) on large images...but has the
        # opposite effect on small images that would fit
        # in a single 'chunk', so use carefully!
        $maxChunkHeight = $LaaT ? 1 : 255;

        $i = 0;
        $range1 = range(0, $h - 1, $maxChunkHeight);

        for ($rowStart = 0; $rowStart < ($h * $maxChunkHeight); $rowStart++) {
            $chunkHeight = $h - $rowStart;
            if ($chunkHeight > $maxChunkHeight) {
                $chunkHeight = $maxChunkHeight;
            }

            # Timeout wait happens here
            $this->serial->sendMessage(18, 0);
            $this->serial->sendMessage(42, 0);
            $this->serial->sendMessage($chunkHeight, 0);
            $this->serial->sendMessage($rowBytesClipped, 0);

            for ($y = 0; $y < $chunkHeight; $y++) {
                for ($x = 0; $x < $rowBytesClipped; $x++) {
                    $this->serial->sendMessage(chr($bitmap[$i]), 0);
                    $i += 1;
                }
                $i += $rowBytes - $rowBytesClipped;
            }
        }

        $this->prevByte = '\n';
    }

    public function printImage(\Imagick $image)
    {
        $width  = $image->getImageWidth();
        $height = $image->getImageHeight();
        $image->cropImage(384, $height, 0, 0);
        if ($width > 384) {
            $width = 384;
        }

        $rowBytes = (int) (($width + 7) / 8);

        $bitmap = array_fill(0, $rowBytes * $height, null);

        for ($y = 0; $y < $height; $y++) {
            $pixels = $image->exportImagePixels(0, $y, $width, 1, 'R', \Imagick::PIXEL_CHAR);
            $pixels = array_map(function ($value) {
                return 0 === $value ? 0 : 255;
            }, $pixels);

            $n = $y * $rowBytes;
            $x = 0;
            for ($b = 0; $b < $rowBytes; $b++) {
                $sum = 0;
                $bit = 128;
                while ($bit > 0) {
                    if ($x >= $width) {
                        break;
                    }
                    if (0 == $pixels[$x]) {
                        $sum |= $bit;
                    }

                    $x++;
                    $bit >>= 1;
                }

                $bitmap[$n + $b] = $sum;
            }
        }

        $bitmap = array_map(function ($value) {
            return $value;
        }, $bitmap);

        $this->printBitmap($width, $height, $bitmap, true);
    }

    # Take the printer offline. Print commands sent after this
    # will be ignored until 'online' is called.
    public function offline()
    {
        $this->writeBytes(27, 61, 0);
    }

    # Take the printer online. Subsequent print commands will be obeyed.
    public function online()
    {
        $this->writeBytes(27, 61, 1);
    }

    # Put the printer into a low-energy state immediately.
    public function sleep()
    {
        $this->sleepAfter(1);
    }

    # Put the printer into a low-energy state after
    # the given number of seconds.
    public function sleepAfter($seconds)
    {
        $this->writeBytes(27, 56, $seconds);
    }

    public function wake()
    {
        $this->timeoutSet(0);
        $this->writeBytes(255);

        for ($i = 0; $i <= 10; $i++):
            $this->writeBytes(27);
            $this->timeoutSet(0.1);
        endfor;
    }

    # Empty method, included for compatibility
    # with existing code ported from Arduino.
    public function listen()
    {
        return;
    }

    # Check the status of the paper using the printers self reporting
    # ability. Doesn't match the datasheet...
    # Returns True for paper, False for no paper.
    # Need to be test because PhpSerial itself is less tested on read operations
    public function hasPaper()
    {
        $this->writeBytes(27, 118, 0);
        # Bit 2 of response seems to be paper status
        $stat = ord($this->serial->readPort(1)) & 0b00000100;
        # If set, we have paper; if clear, no paper
        return ($stat == 0);
    }

    public function setLineHeight($val = 32)
    {
        if ($val < 24) $val = 24;

        $this->lineSpacing = $val - 24;

        # The printer doesn't take into account the current text
        # height when setting line height, making this more akin
        # to inter-line spacing.  Default line spacing is 32
        # (char height of 24, line spacing of 8).
        $this->writeBytes(27, 51, $val);
    }

    # Copied from Arduino lib for parity; is marked 'not working' there
    public function tab()
    {
        $this->writeBytes(9);
    }

    # Copied from Arduino lib for parity; is marked 'not working' there
    public function setCharSpacing($spacing)
    {
        $this->writeBytes(27, 32, 0, 10);
    }

    # Overloading print() in Python pre-3.0 is dirty pool,
    # but these are here to provide more direct compatibility
    # with existing code written for the Arduino library.
    public function pprint()
    {
        $args = func_get_args();
        foreach($args as $arg):
            $this->write((string)$arg);
        endforeach;
    }

    # For Arduino code compatibility again
    public function println()
    {
        $args = func_get_args();
        foreach($args as $arg) {
            $this->write((string) $arg);
        }

        $this->write('\n');
    }
}

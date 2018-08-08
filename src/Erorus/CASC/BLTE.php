<?php

namespace Erorus\CASC;

class BLTE
{
    public $context;

    private $fileHandle;
    private $reportErrors;

    private $streamPosition;

    private $rawBuf = '';
    private $headerSize = false;
    private $chunkCount = 0;
    private $chunkInfo = [];
    private $chunkIndex = -1;
    private $chunkOffset = 0;

    /** @var BLTE\ChunkType */
    private $chunkObject = null;

    private static $encryptionKeys = [];

    public function stream_open($path, $mode, $options, $opened_path) {
        $this->reportErrors = !!($options & STREAM_REPORT_ERRORS);

        if (!preg_match('/^blte:\/\/([\w\W]+)/i', $path, $res)) {
            return false;
        }
        switch ($mode) {
            case 'w':
            case 'x':
                $mode .= 'b';
                break;
            case 'wb':
            case 'xb':
                break;
            default:
                if ($this->reportErrors) {
                    trigger_error("BLTE: Write-only stream", E_USER_ERROR);
                }
                return false;
        }

        $filePath = $res[1];

        $this->fileHandle = fopen($filePath, $mode, !!($options & STREAM_USE_PATH), $this->context);
        if ($this->fileHandle === false) {
            return false;
        }

        $this->streamPosition = 0;

        return true;
    }

    public function stream_close() {
        $this->chunkObject = null; // destruct any chunk object

        fclose($this->fileHandle);
    }

    public function stream_seek($offset, $whence) {
        switch ($whence) {
            case SEEK_SET:
                return ($offset == $this->streamPosition);
                break;
            case SEEK_CUR:
            case SEEK_END:
                return ($offset == 0);
                break;
        }
        return false;
    }

    public function stream_tell() {
        return $this->streamPosition;
    }

    public function stream_eof() {
        return true;
    }

    public function stream_stat() {
        return [
            'size' => $this->streamPosition
        ];
    }

    public function stream_write($data) {
        $writtenBytes = strlen($data);
        $this->rawBuf .= $data;
        $this->streamPosition += strlen($data);

        if ($this->headerSize === false) {
            if ($this->streamPosition < 8) {
                return $writtenBytes;
            }
            if (substr($this->rawBuf, 0, 4) !== 'BLTE') {
                throw new \Exception("Stream is not BLTE encoded\n");
            }
            $this->chunkOffset = $this->headerSize = current(unpack('N', substr($this->rawBuf, 4, 4)));
            $this->rawBuf = substr($this->rawBuf, 8);
        }
        if (!$this->chunkCount) {
            if ($this->headerSize == 0) {
                $this->chunkCount = 1;
                $this->chunkInfo[] = ['encodedSize' => '*', 'id' => 0, 'chunkCount' => 1];
            } else {
                if ($this->streamPosition < 12) {
                    return $writtenBytes;
                }
                $flags            = current(unpack('C', substr($this->rawBuf, 0, 1)));
                $this->chunkCount = current(unpack('N', "\x00" . substr($this->rawBuf, 1, 3)));
                $this->rawBuf     = substr($this->rawBuf, 4);

                if ($this->chunkCount <= 0) {
                    throw new \Exception("BLTE Data is badly formatted: 0 chunks\n");
                }
            }
        }
        while (($this->chunkCount > count($this->chunkInfo)) && strlen($this->rawBuf) >= 24){
            $ci = unpack('NencodedSize/NdecodedSize', substr($this->rawBuf, 0, 8));
            $ci['checksum'] = substr($this->rawBuf, 8, 16);
            $ci['offset'] = $this->chunkOffset;
            $ci['id'] = count($this->chunkInfo);
            $ci['chunkCount'] = $this->chunkCount;

            $this->chunkInfo[] = $ci;
            $this->chunkOffset += $ci['encodedSize'];
            $this->rawBuf = substr($this->rawBuf, 24);
        }
        if ($this->chunkCount > count($this->chunkInfo)) {
            return $writtenBytes;
        }

        while ($this->rawBuf) {
            if (is_null($this->chunkObject)) {
                if (strlen($this->rawBuf) < 1) {
                    return $writtenBytes;
                }
                $this->chunkIndex++;
                $this->chunkObject = BLTE\ChunkType::MakeChunk(
                    substr($this->rawBuf, 0, 1),
                    $this->chunkInfo[$this->chunkIndex],
                    $this->fileHandle);

                $this->rawBuf = substr($this->rawBuf, 1);
            }

            $bytesLeft = $this->chunkObject->getRemainingBytes();
            if ($bytesLeft > strlen($this->rawBuf) && $bytesLeft - strlen($this->rawBuf) < 32) {
                // after this write, we'll have fewer than 32 bytes remaining in this chunk
                // zlib doesn't like adding so few bytes at a time
                // break out of this while loop early so we can make a bigger write later

                break;
            }

            $this->rawBuf = substr($this->rawBuf, $this->chunkObject->Write(substr($this->rawBuf, 0, $bytesLeft)));
            if ($this->chunkObject->getRemainingBytes() <= 0) {
                $this->chunkObject = null;
            }
        }

        return $writtenBytes;
    }

    public function stream_flush() {
        return fflush($this->fileHandle);
    }

    private static function loadHardcodedEncryptionKeys() {
        // see https://wowdev.wiki/CASC#World_of_Warcraft for known keys
        // note: keyname is reversed byte-wise, key is not
        $keys = [
            'FA505078126ACB3E' => 'BDC51862ABED79B2DE48C8E7E66C6200',
            'FF813F7D062AC0BC' => 'AA0B5C77F088CCC2D39049BD267F066D',
            'D1E9B5EDF9283668' => '8E4A2579894E38B4AB9058BA5C7328EE',
            'B76729641141CB34' => '9849D1AA7B1FD09819C5C66283A326EC',
            'FFB9469FF16E6BF8' => 'D514BD1909A9E5DC8703F4B8BB1DFD9A',
            '23C5B5DF837A226C' => '1406E2D873B6FC99217A180881DA8D62',
            'E2854509C471C554' => '433265F0CDEB2F4E65C0EE7008714D9E',
            '8EE2CB82178C995A' => 'DA6AFC989ED6CAD279885992C037A8EE',
            '5813810F4EC9B005' => '01BE8B43142DD99A9E690FAD288B6082',
            '7F9E217166ED43EA' => '05FC927B9F4F5B05568142912A052B0F',
            'C4A8D364D23793F7' => 'D1AC20FD14957FABC27196E9F6E7024A',
            '40A234AEBCF2C6E5' => 'C6C5F6C7F735D7D94C87267FA4994D45',
            '9CF7DFCFCBCE4AE5' => '72A97A24A998E3A5500F3871F37628C0',
            '4E4BDECAB8485B4F' => '3832D7C42AAC9268F00BE7B6B48EC9AF',
            '94A50AC54EFF70E4' => 'C2501A72654B96F86350C5A927962F7A',
            'BA973B0E01DE1C2C' => 'D83BBCB46CC438B17A48E76C4F5654A3',
            '494A6F8E8E108BEF' => 'F0FDE1D29B274F6E7DBDB7FF815FE910',
            '918D6DD0C3849002' => '857090D926BB28AEDA4BF028CACC4BA3',
            '0B5F6957915ADDCA' => '4DD0DC82B101C80ABAC0A4D57E67F859',
            '794F25C6CD8AB62B' => '76583BDACD5257A3F73D1598A2CA2D99',
            'A9633A54C1673D21' => '1F8D467F5D6D411F8A548B6329A5087E',
            '5E5D896B3E163DEA' => '8ACE8DB169E2F98AC36AD52C088E77C1',
            '0EBE36B5010DFD7F' => '9A89CC7E3ACB29CF14C60BC13B1E4616',
            '01E828CFFA450C0F' => '972B6E74420EC519E6F9D97D594AA37C',
            '4A7BD170FE18E6AE' => 'AB55AE1BF0C7C519AFF028C15610A45B',
            '69549CB975E87C4F' => '7B6FA382E1FAD1465C851E3F4734A1B3',
            '460C92C372B2A166' => '946D5659F2FAF327C0B7EC828B748ADB',
            '8165D801CCA11962' => 'CD0C0FFAAD9363EC14DD25ECDD2A5B62',
            'A3F1C999090ADAC9' => 'B72FEF4A01488A88FF02280AA07A92BB',
            '094E9A0474876B98' => 'E533BB6D65727A5832680D620B0BC10B',
            '3DB25CB86A40335E' => '02990B12260C1E9FDD73FE47CBAB7024',
            '0DCD81945F4B4686' => '1B789B87FB3C9238D528997BFAB44186',
            '486A2A3A2803BE89' => '32679EA7B0F99EBF4FA170E847EA439A',
            '71F69446AD848E06' => 'E79AEB88B1509F628F38208201741C30',
            '211FCD1265A928E9' => 'A736FBF58D587B3972CE154A86AE4540',
            '0ADC9E327E42E98C' => '017B3472C1DEE304FA0B2FF8E53FF7D6',
            'BAE9F621B60174F1' => '38C3FB39B4971760B4B982FE9F095014',
            '34DE1EEADC97115E' => '2E3A53D59A491E5CD173F337F7CD8C61',
            'E07E107F1390A3DF' => '290D27B0E871F8C5B14A14E514D0F0D9',
            '32690BF74DE12530' => 'A2556210AE5422E6D61EDAAF122CB637',
            'BF3734B1DCB04696' => '48946123050B00A7EFB1C029EE6CC438',
            '74F4F78002A5A1BE' => 'C14EEC8D5AEEF93FA811D450B4E46E91',
            '78482170E4CFD4A6' => '768540C20A5B153583AD7F53130C58FE',
            'B1EB52A64BFAF7BF' => '458133AA43949A141632C4F8596DE2B0',
            'FC6F20EE98D208F6' => '57790E48D35500E70DF812594F507BE7',
            '402CFABF2020D9B7' => '67197BCD9D0EF0C4085378FAA69A3264',
            '6FA0420E902B4FBE' => '27B750184E5329C4E4455CBD3E1FD5AB',
            'C1E5D7408A7D4484' => 'A7D88E52749FA5459D644523F8359651',
            'E46276EB9E1A9854' => 'CCCA36E302F9459B1D60526A31BE77C8',
        ];

        foreach ($keys as $k => $v) {
            static::$encryptionKeys[strrev(hex2bin($k))] = hex2bin($v);
        }
    }

    public static function loadEncryptionKeys($keys = []) {
        if (count(static::$encryptionKeys) == 0) {
            static::loadHardcodedEncryptionKeys();
        }

        foreach ($keys as $k => $v) {
            static::$encryptionKeys[$k] = $v;
        }
    }

    public static function getEncryptionKey($keyName) {
        return static::$encryptionKeys[$keyName] ?? false;
    }
}

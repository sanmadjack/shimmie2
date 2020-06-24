<?php declare(strict_types=1);
class MimeSystemTest extends ShimmiePHPUnitTestCase
{
    public function testJPEG()
    {
        $result = MimeType::get_for_file("tests/bedroom_workshop.jpg");
        $this->assertEquals(MimeType::JPEG, $result);
    }

    public function testGIF()
    {
        $result = MimeType::get_for_file("tests/favicon.gif");
        $this->assertEquals($result, MimeType::GIF);
    }

    public function testPNG()
    {
        $result = MimeType::get_for_file("tests/favicon.png");
        $this->assertEquals($result, MimeType::PNG);
    }

    public function testWEBP()
    {
        $result = MimeType::get_for_file("tests/favicon.webp");
        $this->assertEquals($result, MimeType::WEBP);
    }

    public function testZIP()
    {
        $result = MimeType::get_for_file("tests/test.zip");
        $this->assertEquals($result, MimeType::ZIP);
    }

    // TODO Find public domain flash file I can use here
//    public function testFlash()
//    {
//        $result = MimeType::get_for_file("tests/test.swf");
//        $this->assertEquals($result,MimeType::Flase);
//    }

    public function testWEBM()
    {
        $result = MimeType::get_for_file("tests/big-buck-bunny_trailer.webm");
        $this->assertEquals($result, MimeType::WEBM);
    }

    public function testMP4()
    {
        $result = MimeType::get_for_file("tests/test.mp4");
        $this->assertEquals($result, MimeType::MP4_VIDEO);
    }

    public function testOGV()
    {
        $result = MimeType::get_for_file("tests/test.ogv");
        $this->assertEquals($result, MimeType::OGG_VIDEO);
    }

    public function testFLV()
    {
        $result = MimeType::get_for_file("tests/test.flv");
        $this->assertEquals($result, MimeType::FLASH_VIDEO);
    }

    public function testAVI()
    {
        $result = MimeType::get_for_file("tests/drop.avi");
        $this->assertEquals($result, MimeType::AVI);
    }

    public function testMKV()
    {
        $result = MimeType::get_for_file("tests/test.mkv");
        $this->assertEquals($result, MimeType::MKV);
    }

    public function testMP3()
    {
        $result = MimeType::get_for_file("tests/test.mp3");
        $this->assertEquals($result, MimeType::MP3);
    }

    public function testMOV()
    {
        $result = MimeType::get_for_file("tests/ugariticalphabetinorder.mov");
        $this->assertEquals($result, MimeType::QUICKTIME);
    }

    public function testASF()
    {
        $result = MimeType::get_for_file("tests/test.asx");
        $this->assertEquals($result, MimeType::ASF);
    }

    public function testICO()
    {
        $result = MimeType::get_for_file("tests/favicon.ico");
        $this->assertEquals($result, MimeType::ICO);
    }


//    public function testCUR()
//    {
//        $result = MimeType::get_for_file("tests/test.cur", "cur");
//        $this->assertEquals($result,MimeType::Cur); // ???
//    }

    public function testANI()
    {
        $result = MimeType::get_for_file("tests/test.ani", "ani");
        $this->assertEquals($result, MimeType::ANI); // ???
    }

    public function testCBZ()
    {
        $result = MimeType::get_for_file("tests/test.cbz", "cbz");
        $this->assertEquals($result, MimeType::COMIC_ZIP); // ???
    }

    public function testSVG()
    {
        $result = MimeType::get_for_file("tests/test.svg");
        $this->assertEquals($result, MimeType::SVG);
    }
}

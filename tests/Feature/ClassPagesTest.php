<?php

namespace Tests\Feature;

use App\Http\Controllers\ClassPagesController;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Search pages (/watercolor-classes-calgary …) and /sitemap.xml: real text in
 * the HTML, only classes that fit the page and haven't happened yet, and the
 * home page linking to them.
 */
class ClassPagesTest extends TestCase
{
    use RefreshDatabase;

    private function catalog(): void
    {
        $dir = sys_get_temp_dir() . '/class-pages-' . getmypid();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/content.json', json_encode([
            'events' => [
                ['id' => 46, 'eventName' => 'POPPY BLOOMS WATERCOLOR CLASS', 'date' => '2026-11-11', 'day' => 'Wednesday',
                 'time' => '6:00 pm - 9:00 pm', 'price' => 84, 'img' => 'assets/img/uploaded/poppy.png',
                 'modalDescription' => "Explore the beauty and spontaneity of watercolor.\n\nSecond paragraph."],
                ['id' => 52, 'eventName' => 'SUNSET GLOW ACRYLIC WORKSHOP', 'date' => '2026-12-02', 'day' => 'Wednesday',
                 'time' => '6:00 pm - 9:00 pm', 'price' => 84, 'img' => 'assets/img/uploaded/sunset.jpg'],
                ['id' => 40, 'eventName' => 'OLD WATERCOLOR CLASS', 'date' => '2026-10-30', 'day' => 'Friday',
                 'time' => '6:00 pm - 9:00 pm', 'price' => 84, 'img' => 'assets/img/uploaded/old.jpg'],
                ['id' => 41, 'eventName' => '5-WEEK PAINTING COURSE: “PLAYING WITH WATERCOLOR”', 'date' => '2026-11-30', 'day' => 'Mondays',
                 'time' => '11:00 am - 2:00 pm', 'price' => 367.5, 'img' => 'assets/img/uploaded/course.png'],
            ],
            'regularClasses' => [
                ['id' => 2, 'eventName' => 'PRIVATE ART CLASSES', 'time' => '-', 'date' => '%', 'day' => 'Monday - Friday',
                 'price' => 75, 'modalDescription' => 'Creativity, dreams, inspiration - the world of art awaits you!', 'modalDiscount' => 'for 1,5 hour'],
            ],
            'siteText' => [
                'aboutParagraphs' => ['Good to see you here! My name is Alevtyna.'],
                'faq' => [['question' => 'Do I need to bring my own materials or supplies?', 'answer' => 'All materials and supplies are included.']],
                'footerEmail' => 'a.art.shuhai@gmail.com',
            ],
        ]));
        $this->app->usePublicPath($dir);
        $this->travelTo(Carbon::parse('2026-11-09 12:00', 'America/Edmonton'));
    }

    public function test_every_page_renders_with_its_own_title_and_heading(): void
    {
        $this->catalog();
        foreach (ClassPagesController::PAGES as $slug => $p) {
            $this->get('https://art-shuhai.com/' . $slug)->assertOk()
                ->assertSee('<title>' . e($p['title']) . '</title>', false)
                ->assertSee('<h1>' . e($p['h1']) . '</h1>', false)
                ->assertSee('<link rel="canonical" href="https://art-shuhai.com/' . $slug . '">', false)
                ->assertSee('Good to see you here! My name is Alevtyna.')
                ->assertSee('Do I need to bring my own materials or supplies?');
        }
    }

    public function test_a_page_lists_only_upcoming_classes_of_its_kind(): void
    {
        $this->catalog();
        $this->get('https://art-shuhai.com/watercolor-classes-calgary')->assertOk()
            ->assertSee('Poppy Blooms Watercolor Class')
            ->assertSee('https://art-shuhai.com/event/poppy-blooms-watercolor-class', false)
            ->assertSee('Wednesday, November 11 · 6:00 pm – 9:00 pm')
            ->assertSee('Explore the beauty and spontaneity of watercolor.')
            ->assertDontSee('Second paragraph.')
            ->assertSee('Playing with Watercolor')
            ->assertDontSee('Sunset Glow')
            ->assertDontSee('Old Watercolor Class');

        $this->get('https://art-shuhai.com/acrylic-painting-classes-calgary')->assertOk()
            ->assertSee('Sunset Glow Acrylic Workshop')->assertDontSee('Poppy Blooms');

        $this->get('https://art-shuhai.com/paint-night-calgary')->assertOk()
            ->assertSee('Sunset Glow Acrylic Workshop')->assertSee('Poppy Blooms')
            ->assertDontSee('Playing with Watercolor');          // 11 am — not an evening

        $this->get('https://art-shuhai.com/private-art-lessons-calgary')->assertOk()
            ->assertSee('Private Art Classes')->assertSee('$75 for 1.5 hour')->assertSee('Monday - Friday')
            ->assertDontSee('Poppy Blooms');
    }

    public function test_sitemap_lists_pages_and_bookable_classes_only(): void
    {
        $this->catalog();
        $res = $this->get('https://art-shuhai.com/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string($res->getContent());
        $locs = [];
        foreach ($xml->url as $u) {
            $locs[] = (string) $u->loc;
        }
        $this->assertContains('https://art-shuhai.com/', $locs);
        $this->assertContains('https://art-shuhai.com/watercolor-classes-calgary', $locs);
        $this->assertContains('https://art-shuhai.com/event/poppy-blooms-watercolor-class', $locs);
        $this->assertContains('https://art-shuhai.com/event/private-art-classes', $locs);
        $this->assertNotContains('https://art-shuhai.com/event/old-watercolor-class', $locs);
    }

    public function test_home_page_names_calgary_and_links_to_the_pages(): void
    {
        $this->catalog();
        $this->withoutVite();
        $res = $this->get('https://art-shuhai.com/')->assertOk()
            ->assertSee('<title>Art Classes in Calgary with Alevtyna | Shuhai Art Studio</title>', false);
        foreach (array_keys(ClassPagesController::PAGES) as $slug) {
            $res->assertSee('href="/' . $slug . '"', false);
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Category;
use App\Models\CmsPage;
use App\Models\ContactMessage;
use App\Models\Faq;
use App\Models\Post;
use App\Models\Product;
use App\Models\Testimonial;
use Illuminate\Http\Request;

class StorefrontController extends Controller
{
    public function home()
    {
        return view('storefront.home', [
            'slides' => $this->heroSlides(),
            'featured' => Product::with('images')->where('is_active', true)->where('is_featured', true)->latest()->take(8)->get()
                ?: Product::with('images')->where('is_active', true)->latest()->take(8)->get(),
            'categories' => Category::where('domain', 'ecommerce')->whereNull('parent_id')->where('is_active', true)->get(),
            'posts' => Post::published()->latest('published_at')->take(3)->get(),
            'testimonials' => Testimonial::published()->orderBy('sort')->take(6)->get(),
        ]);
    }

    /**
     * Home hero slides. Text is translated on render via tr(); `image` is a locally
     * stored asset (public/images/slides) rendered with a soft golden glow in the view;
     * `gradient` remains as the fallback wash behind the photo while it loads.
     */
    protected function heroSlides(): array
    {
        return [
            [
                'eyebrow' => 'Crafted with heritage',
                'title' => 'Timeless gold & silver, for every celebration',
                'subtitle' => 'Shop chains, bangles, rings and accessories — priced live, delivered worldwide, in your language and currency.',
                'cta' => 'Explore the collection',
                'link' => '/shop',
                'image' => asset('images/slides/slide-gold-heritage.jpg'),
                'gradient' => 'from-brand-dark via-brand to-brand-dark',
            ],
            [
                'eyebrow' => 'Live gold & silver rates',
                'title' => 'Pure 22K & 18K, priced to the minute',
                'subtitle' => 'Transparent, real-time pricing on every gold and silver piece — no surprises at checkout.',
                'cta' => 'Shop gold',
                'link' => '/shop?material=gold',
                'image' => asset('images/slides/slide-live-rates.jpg'),
                'gradient' => 'from-brand-dark via-amber-800 to-brand-gold',
            ],
            [
                'eyebrow' => 'New season accessories',
                'title' => 'Everyday elegance, effortlessly yours',
                'subtitle' => 'Discover our latest accessories collection — finely finished, beautifully affordable.',
                'cta' => 'Browse accessories',
                'link' => '/shop?material=accessory',
                'image' => asset('images/slides/slide-accessories.jpg'),
                'gradient' => 'from-stone-900 via-brand-dark to-brand',
            ],
        ];
    }

    public function shop(Request $request)
    {
        $query = Product::with('images')->where('is_active', true);

        if ($request->filled('category')) {
            $query->where(fn ($q) => $q->where('category_id', $request->category)->orWhere('subcategory_id', $request->category));
        }
        if ($request->filled('material')) {
            $query->where('material', $request->material);
        }
        if ($request->filled('q')) {
            $query->where('code', 'like', '%' . $request->q . '%');
        }

        return view('storefront.shop', [
            'products' => $query->latest()->paginate(12)->withQueryString(),
            'categories' => Category::where('domain', 'ecommerce')->whereNull('parent_id')->where('is_active', true)->get(),
            'activeCategory' => $request->category,
        ]);
    }

    public function product(int $id)
    {
        $product = Product::where('is_active', true)->with(['category', 'images'])->findOrFail($id);

        return view('storefront.product', [
            'product' => $product,
            'related' => Product::with('images')->where('is_active', true)->where('category_id', $product->category_id)
                ->where('id', '!=', $product->id)->take(4)->get(),
        ]);
    }

    public function posts(string $type)
    {
        abort_unless(in_array($type, ['blog', 'news']), 404);

        return view('storefront.posts', [
            'type' => $type,
            'posts' => Post::published()->type($type)->latest('published_at')->paginate(9),
        ]);
    }

    public function postShow(string $type, string $slug)
    {
        $post = Post::published()->type($type)->where('slug', $slug)->firstOrFail();

        return view('storefront.post', ['post' => $post, 'type' => $type]);
    }

    public function page(string $slug)
    {
        $page = CmsPage::published()->where('slug', $slug)->firstOrFail();

        return view('storefront.page', ['page' => $page]);
    }

    public function faq()
    {
        return view('storefront.faq', ['faqs' => Faq::published()->orderBy('sort')->get()]);
    }

    public function stores()
    {
        return view('storefront.stores', [
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function contact()
    {
        return view('storefront.contact');
    }

    public function contactSubmit(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'required|email|max:150',
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{7,20}$/'],
            'message' => 'required|string|max:2000',
        ], [
            'phone.regex' => 'Please enter a valid phone number.',
        ]);

        ContactMessage::create($data + ['ip' => $request->ip()]);

        // HQ bell: a new website inquiry awaits reply (board 2026-08-11).
        \App\Services\Push\Notifier::admins(
            'New contact inquiry — ' . ($data['name'] ?? 'website visitor'),
            (string) str((string) ($data['message'] ?? ''))->limit(120),
            url: '/admin/contact-messages',
        );

        return back()->with('success', 'Thank you — your message has been received. We will get back to you shortly.');
    }
}

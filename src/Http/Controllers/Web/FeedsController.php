<?php
namespace NexaMerchant\Feeds\Http\Controllers\Web;

use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Feed\Feed;
use Spatie\Feed\Helpers\ResolveFeedItems;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Models\Product;


class FeedsController extends Controller
{
    public function __construct(
        protected ProductRepository $productRepository
    )
    {
        
    }
    // generate by json for klaviyo
    public function atom(Request $request)
    {
        // 
        $products = $this->productRepository->all();


    }

    /**
     * 
     * @param Request $request
     * 
     * 
     * @link https://github.com/klaviyo/devportal/blob/master/custom_catalog_example.json
     * @return \Illuminate\Http\JsonResponse
     * 
     */
    public function klaviyo(Request $request)
    {
        //
        $products = Product::where('type', 'configurable')->orderBy("updated_at","desc")->limit(30)->get();
        $items = [];
        foreach($products as $key => $product) {
            $image_url = $product->images->first() ? $product->images->first()->url : '';
            $image_url = str_replace(config('app.url').'/storage/'.config('app.url').'/storage/', config('app.url') . '/storage/', $image_url);
            $item = [
                'id' => config('onebuy.brand').'#'.$product->id,
                'title' => $product->name,
                'description' => $product->description,
                'link' => config('services.shop.url') .'/products/'. $product->url_key,
                'categories' => [config('onebuy.brand')],
                'image_link' => $image_url,
                'price' => $product->price,
                'availability' => $product->isSaleable() ? 'in stock' : 'out of stock'
            ];
            $items[] = $item;
        }

        return response()->json($items);
    }

    

    
}
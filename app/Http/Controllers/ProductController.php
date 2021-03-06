<?php

namespace App\Http\Controllers;

use RedisManager;
use Carbon\Carbon;
use App\Models\Product;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Http\Resources\ProductCollection;

class ProductController extends Controller
{
  public function index(Request $request)
  {
    $data = Product::search($request->input('q'), "name")
      ->leftJoin('ratings', 'ratings.product_id', '=', 'products.id')
      ->select([
        'products.id',
        'products.name',
        'products.slug',
        'products.price',
        'products.sale_off_price',
        'products.featured_image',
        DB::raw('AVG(rating) as ratings_average')
        // DB::raw('CAST(AVG(rating) as FLOAT) as ratings_average') // mariadb 10.4.5/mysql 8.0.17
      ])
      ->groupBy('id')
      ->when($request->input("onsale"), function ($query) {
        $query->onSale();
      })
      ->when($request->input("range"), function ($query, $range) {
        $query->where(function ($query) use ($range) {
          $query->whereBetween('price', $range)
            ->orWhereBetween('sale_off_price', $range);
        });
      })
      ->when($request->input('tags'), function ($query, $id) {
        $query->whereHas('tags', function ($query) use ($id) {
          $query->whereIn('tag_id', Arr::wrap($id));
        });
      })
      ->when($request->input('brands'), function ($query, $id) {
        $query->whereIn('brand_id', Arr::wrap($id));
      })
      ->when($request->input('categories'), function ($query, $id) {
        $query->whereIn('category_id', Arr::wrap($id));
      })
      ->when($request->input('rating'), function ($query, $rating) {
        $query->having(DB::raw('AVG(rating)'), '>=', $rating);
      })
      ->withCount('orders');
    $sortBy = $request->input("sortBy");
    $sortDesc = $request->input("sortDesc") == "false" ? "asc" : "desc";
    switch ($sortBy) {
      case "name":
        $data = $data->orderBy("name", $sortDesc);
        break;
      case "price":
        $data = $data->orderBy("price", $sortDesc);
        break;
      case "quantity":
        $data = $data->orderBy("quantity", $sortDesc);
        break;
      case "orders_count":
        $data = $data->orderBy("orders_count", $sortDesc);
        break;
      case "sale_off_percent":
        $data = $data->orderBy("sale_off_percent", $sortDesc);
        break;
      case "rating":
        $data = $data->orderBy("ratings_average", $sortDesc);
        break;
      case "random":
        $data = $data->inRandomOrder();
      default:
        $data = $data->orderBy('products.created_at', 'desc');
    }
    return response()->json(
      new ProductCollection($data->paginate($request->input('per_page', 8)))
    );
  }

  public function show($slug, Request $request)
  {
    $product = Cache::remember('cache_product_' . $slug, 60 * 60 * 24, function () use ($slug, $request) {
      $product = Product::where("slug", $slug)
        // ->leftJoin('ratings', 'ratings.product_id', '=', 'products.id')
        ->leftJoin('ratings', function ($query) {
          $query->on('ratings.product_id', '=', 'products.id')
            ->where('ratings.approved', 1);
        })
        ->select([
          'products.id',
          'products.brand_id',
          'products.category_id',
          'products.name',
          'products.slug',
          'products.description',
          'products.content',
          'products.price',
          'products.sale_off_price',
          'products.sale_off_quantity',
          'products.quantity',
          'products.featured_image',
          DB::raw('AVG(rating) as ratings_average')
          // DB::raw('CAST(AVG(rating) as FLOAT) as ratings_average') // mariadb 10.4.5/mysql 8.0.17
        ])
        ->groupBy('id')
        ->with([
          "brand:id,name,slug",
          "images:url,product_id",
          "category:id,name,slug",
          "tags",
          "ratings" => function ($query) {
            $query->select('rating', 'product_id', DB::raw('count(*) as total'))
              ->where("approved", 1)
              ->groupBy('rating');
          }
        ])
        ->withCount(["ratings" => function ($query) {
          $query->where("approved", 1);
        }])
        ->firstOrFail();
      $product->images->makeHidden(['product_id']);
      // $product->ratingsWithUser->makeHidden(['product_id', 'user_id', 'approved', 'created_at', 'updated_at']);
      $product->ratings->makeHidden(['product_id']);
      $product->ratings_average = (float) $product->ratings_average;
      return $product;
    });
    $periods = ["day", "week", "month", "year"];
    $client_ip = $request->ip();
    $redis_prefix = "products_visits";
    if (RedisManager::ttl($redis_prefix . "_recorded_ips:$product->id:$client_ip") < 0 || !RedisManager::exists($redis_prefix . "_recorded_ips:$product->id:$client_ip")) {
      foreach ($periods as $period) {
        if (RedisManager::ttl($redis_prefix . "_" . $period) < 0 || !RedisManager::exists($redis_prefix . "_" . $period)) {
          $periodCarbon = Carbon::now()->{'endOf' . Str::studly($period)}();
          $expireInSeconds = $periodCarbon->diffInSeconds() + 1;
          RedisManager::incrBy($redis_prefix . "_" . $period . "_total", 0);
          RedisManager::zIncrBy($redis_prefix . "_" . $period, 0, 0);
          RedisManager::expire($redis_prefix . "_" . $period . "_total", $expireInSeconds);
          RedisManager::expire($redis_prefix . "_" . $period, $expireInSeconds);
        }
        RedisManager::incr($redis_prefix . "_" . $period . "_total");
        RedisManager::zIncrBy($redis_prefix . "_" . $period, 1, $product->id);
      }
      RedisManager::incr($redis_prefix . "_total");
      RedisManager::zIncrBy($redis_prefix, 1, $product->id);
      RedisManager::set($redis_prefix . "_recorded_ips:$product->id:$client_ip", true);
      // limit view count by 1 per 15*60secs
      RedisManager::expire($redis_prefix . "_recorded_ips:$product->id:$client_ip", 15 * 60);
    }
    $product->view_count = RedisManager::zScore($redis_prefix, $product->id) ?: 0;
    return response()->json([
      'product' => $product
    ]);
  }

  public function showById(Request $request)
  {
    $listId = Arr::wrap($request->input("listId"));
    $order = implode(',', $listId);
    $data = Product::whereIn('id', $listId)
      ->orderByRaw("FIELD(id, $order)")
      ->get()
      ->keyBy('id');
    return response()->json([
      'product' => $data
    ]);
  }
}

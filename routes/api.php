<?php

use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\categoryController;
use App\Http\Controllers\Admin\CategoryFieldsController;
use App\Http\Controllers\Admin\CategoryPlanPricesController;
use App\Http\Controllers\Admin\CategorySectionsController;
use App\Http\Controllers\Admin\PackagesController;
use App\Http\Controllers\Admin\UserSubscriptionsController;
use App\Http\Controllers\Admin\ChatController as AdminChatController;
use App\Http\Controllers\Admin\MonitoringController;
use App\Http\Controllers\Admin\BroadcastController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CarController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\FavoriteController;
use App\Support\Section;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\SystemSettingController;
use App\Http\Controllers\Admin\StatsController;
use App\Http\Controllers\Admin\TransactionsController;
use App\Http\Controllers\BestAdvertiserController;
use App\Models\Listing as ListingModel;
use App\Http\Controllers\Admin\GovernorateController;
use App\Http\Controllers\Admin\MakeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\PlansController;
use App\Http\Controllers\ListingReportController;
use App\Http\Controllers\OtpController;

Route::post('/otp/send', [OtpController::class, 'send']);
Route::post('/otp/verify', [OtpController::class, 'verify']);

Route::post('/register', [AuthController::class, 'register']);
Route::get('v1/test', fn() => response()->json(['ok' => true]));

// Debug route to test if requests are reaching Laravel
Route::get('debug/test', function() {
    \Log::info('DEBUG TEST ROUTE HIT');
    return response()->json([
        'success' => true,
        'message' => 'Debug route working',
        'timestamp' => now()->toIso8601String()
    ]);
});

// Public Category Fields Route
Route::get('category-fields', [CategoryFieldsController::class, 'index']);
// Public Categories Route
Route::get('categories', [categoryController::class, 'index']);
//public governorates routes
Route::get('governorates', [GovernorateController::class, 'index']);
Route::get('governorates/{governorate}/cities', [GovernorateController::class, 'cities']);

//public makes routes
Route::get('makes', [MakeController::class, 'index']);

Route::get('makes/{make}/models', [MakeController::class, 'models']);

//public main category
Route::get('/main-sections', [CategorySectionsController::class, 'index']);
Route::get('/sub-sections/{mainSection}', [CategorySectionsController::class, 'subSections']);

Route::get('/system-settings', [SystemSettingController::class, 'index']);

Route::get('/plan-prices', [SubscriptionController::class, 'pricesByCategory']);

//public listing for specific user
Route::get('users/{user}', [UserController::class, 'showUserWithListings']);
//get public  listing best
Route::get('/the-best/{section}', [BestAdvertiserController::class, 'index']);

// Global Search across all listings
Route::get('/listings/search', [ListingController::class, 'globalSearch']);

Route::get('banners', [BannerController::class, 'index']);


Route::prefix('v1/{section}')->group(function () {
    Route::bind('listing', function ($value) {
        $section = request()->route('section');
        if (is_string($section) && $section !== '') {
            $sec = Section::fromSlug($section);
            return ListingModel::where('id', $value)
                ->where('category_id', $sec->id())
                ->firstOrFail();
        }
        return ListingModel::findOrFail($value);
    });

    Route::get('ping', function (string $section) {
        $sec = Section::fromSlug($section);
        return response()->json([
            'section' => $sec->slug,
            'category_id' => $sec->id(),
            'fields_count' => count($sec->fields),
        ]);
    });

    Route::post('validate-sample', function (App\Http\Requests\GenericListingRequest $req) {
        return response()->json(['ok' => true, 'data' => $req->validated()]);
    });


    // Route::apiResource('listings', ListingController::class)->only(['index', 'show']);

    // Route::apiResource('listings', ListingController::class)->only(['index', 'show']);
    Route::get('plans', [PlansController::class, 'show']);
    
    // Public route for listing ads (search/browse)
    Route::get('listings', [ListingController::class, 'index']);
    Route::get('listings/{listing}', [ListingController::class, 'show']);
    Route::post('listings/{listing}/contact-click', [ListingController::class, 'trackContactClick']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::apiResource('listings', ListingController::class)->only(['store', 'update', 'destroy']);
    });
});


Route::prefix('admin')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function () {
        // Category Fields Routes
        Route::get('category-fields', [CategoryFieldsController::class, 'index']);
        Route::post('category-fields', [CategoryFieldsController::class, 'store']);
        Route::post('category-fields/{categoryField}', [CategoryFieldsController::class, 'update']);
        Route::delete('category-fields/{categoryField}', [CategoryFieldsController::class, 'destroy']);

        // Category Routes
        Route::get('categories/usage-report', [categoryController::class, 'usageReport']);
        Route::post('categories', [CategoryController::class, 'store']);
        Route::put('categories/{category}', [CategoryController::class, 'update']);
        Route::delete('categories/{category}', [CategoryController::class, 'destroy']);
        //all categories for admin
        Route::get('categories', [categoryController::class, 'index']);
        Route::get('categories/{category}', [categoryController::class, 'show']);
        
        // Category Unified Image Routes
        Route::put('categories/{category}/toggle-global-image', [categoryController::class, 'toggleGlobalImage']);
        Route::post('categories/{category}/upload-global-image', [categoryController::class, 'uploadGlobalImage'])
            ->middleware('throttle:10,1'); // Rate limit: 10 uploads per minute
        Route::delete('categories/{category}/global-image', [categoryController::class, 'deleteGlobalImage']);
        
        // Category Option Ranks Route
        Route::post('categories/{slug}/options/ranks', [CategoryController::class, 'updateOptionRanks']);

        // System Settings Routes
        // Route::apiResource('system-settings', SystemSettingController::class);

        // Admin Stats Route
        Route::get('stats', [StatsController::class, 'index']);
        Route::get('recent-activities', [StatsController::class, 'recentActivities']);
        Route::get('users-summary', [StatsController::class, 'usersSummary']);
        Route::get('pending-listings', [StatsController::class, 'pendingListings']);
        Route::get('ads-not-payment', [StatsController::class, 'adsNOTPayment']);
        Route::get('published-listings', [StatsController::class, 'publishedListings']);
        Route::get('rejected-listings', [StatsController::class, 'rejectedListings']);
        Route::patch('listings/{listing}/approve', [StatsController::class, 'approveListing']);
        Route::patch('listings/{listing}/accept-not-payment', [StatsController::class, 'AcceptAdsNotPayment']);
        Route::patch('listings/{listing}/reject', [StatsController::class, 'rejectListing']);
        Route::patch('/listings/{listing}/reopen', [StatsController::class, 'reopen']);
        Route::get('transactions', [TransactionsController::class, 'index']);

        // Admin Users management
        Route::get('users/{user}', [UserController::class, 'showUserWithListings']);
        Route::put('users/{user}', [UserController::class, 'updateUser']);
        Route::post('users', [UserController::class, 'storeUser']);
        Route::delete('users/{user}', [UserController::class, 'deleteUser']);
        Route::patch('users/{user}/block', [UserController::class, 'blockedUser']);
        // Route::get('users/{user}/listings', [UserController::class, 'userListings']);

        //Best Advertiser
        Route::get('/featured/{userId}', [BestAdvertiserController::class, 'show']);
        Route::post('/featured', [BestAdvertiserController::class, 'store']);
        Route::put('/disable/{bestAdvertiser}', [BestAdvertiserController::class, 'disable']);
        //change  password
        Route::put('/change-password/{user}', [AuthController::class, 'changePass']);
        //Admin Delegates
        Route::get('delegates/{user}/clients', [UserController::class, 'delegateClients']);
        //create otp
        Route::post('/create-otp/{user}', [UserController::class, 'createOtp']);

        //system settings
        Route::match(['post', 'put'], '/system-settings/upload-image', [SystemSettingController::class, 'uploadDefaultImage']);
        Route::post('/system-settings', [SystemSettingController::class, 'store']);
        //User packages
        Route::post('/user-packages', [PackagesController::class, 'storeOrUpdate']);
        Route::get('/packages', [PackagesController::class, 'index']);
        Route::get('/users/{user}/package', [PackagesController::class, 'getUserPackage']);



        Route::post('governorates/ranks', [GovernorateController::class, 'updateGovRanks']);
        Route::post('cities/ranks', [GovernorateController::class, 'updateCityRanks']);
        Route::post('governorates', [GovernorateController::class, 'storeGov']);
        Route::post('/city/{governorate}', [GovernorateController::class, 'storCities']);
        Route::put('governorates/{governorate}', [GovernorateController::class, 'updateGov']);
        Route::delete('governorates/{governorate}', [GovernorateController::class, 'destroyGov']);
        // Route::post('governorates/{governorate}/cities', [GovernorateController::class, 'addCity']);
        Route::put('cities/{city}', [GovernorateController::class, 'updateCity']);
        Route::delete('cities/{city}', [GovernorateController::class, 'deleteCity']);
        Route::get('cities/mappings', [GovernorateController::class, 'getCitiesMappings']);



        Route::post('makes', [MakeController::class, 'addMake']);
        Route::put('makes/{make}', [MakeController::class, 'update']);
        Route::delete('makes/{make}', [MakeController::class, 'destroy']);

        Route::post('makes/{make}/models', [MakeController::class, 'addModel']);
        Route::put('models/{model}', [MakeController::class, 'updateModel']);
        Route::delete('models/{model}', [MakeController::class, 'deleteModel']);


        Route::post('/main-section/{categorySlug}', [CategorySectionsController::class, 'storeMain']);
        Route::post('/sub-section/{mainSection}', [CategorySectionsController::class, 'addSubSections']);
        Route::put('/main-section/{mainSection}', [CategorySectionsController::class, 'updateMain']);
        Route::put('/sub-section/{subSection}', [CategorySectionsController::class, 'updateSub']);
        Route::delete('/main-section/{mainSection}', [CategorySectionsController::class, 'destroyMain']);
        Route::delete('/sub-section/{subSection}', [CategorySectionsController::class, 'destroySub']);

        Route::post('/category-sections/main/ranks', [CategorySectionsController::class, 'updateMainRanks']);
        Route::post('/category-sections/sub/ranks', [CategorySectionsController::class, 'updateSubRanks']);

        Route::get('category-plan-prices', [CategoryPlanPricesController::class, 'index']);
        Route::post('category-plan-prices', [CategoryPlanPricesController::class, 'store']);

        // Banner Management
        Route::get('banners', [BannerController::class, 'index']);
        Route::post('banners', [BannerController::class, 'store']);
        Route::put('banners/{slug}', [BannerController::class, 'update']);

        // User Subscriptions (per category)
        Route::get('user-subscriptions', [UserSubscriptionsController::class, 'index']);
        Route::get('user-subscriptions/{id}', [UserSubscriptionsController::class, 'show']);
        Route::post('user-subscriptions', [UserSubscriptionsController::class, 'store']);
        Route::patch('user-subscriptions/{id}', [UserSubscriptionsController::class, 'update']);
        Route::delete('user-subscriptions/{id}', [UserSubscriptionsController::class, 'destroy']);
        Route::post('user-subscriptions/{id}/add-ads', [UserSubscriptionsController::class, 'addAds']);

        // Listing Reports (Admin)
        Route::get('listing-reports', [ListingReportController::class, 'index']);
        Route::post('listing-reports/{listing}/accept', [ListingReportController::class, 'acceptReport']);
        Route::post('listing-reports/{listing}/dismiss', [ListingReportController::class, 'dismissReport']);
        // Route::post('listing-reports/{listing}/reopen', [ListingReportController::class, 'reopen']);
        Route::patch('listing-reports/{id}/read', [ListingReportController::class, 'markAsRead']);
        Route::delete('listing-reports/{report}', [ListingReportController::class, 'destroy']);

        // Admin Notifications
        Route::get('admin-notifications', [AdminNotificationController::class, 'index']);
        Route::get('admin-notifications/count', [AdminNotificationController::class, 'unreadCount']);
        Route::patch('admin-notifications/{id}/read', [AdminNotificationController::class, 'markAsRead']);

        // Admin Support Chat Routes (Unified Inbox)
        Route::prefix('support')->group(function () {
            Route::get('/inbox', [AdminChatController::class, 'supportInbox']);
            Route::get('/stats', [AdminChatController::class, 'stats']);
            Route::post('/reply', [AdminChatController::class, 'reply']);
            Route::get('/{user}', [AdminChatController::class, 'supportHistory']);
            Route::patch('/{user}/read', [AdminChatController::class, 'markAsRead']);
        });

        // Monitoring Routes (Read-Only with special middleware)
        Route::prefix('monitoring')->middleware('can.monitor.chat')->group(function () {
            Route::get('/conversations', [MonitoringController::class, 'index']);
            Route::get('/conversations/{conversationId}', [MonitoringController::class, 'show']);
            Route::get('/search', [MonitoringController::class, 'search']);
            Route::get('/stats', [MonitoringController::class, 'stats']);
        });

        // Broadcast Routes (Bulk Messages)
        Route::prefix('broadcast')->group(function () {
            Route::post('/', [BroadcastController::class, 'send']);
            Route::post('/group', [BroadcastController::class, 'sendToGroup']);
            Route::post('/preview', [BroadcastController::class, 'preview']);
            Route::get('/history', [BroadcastController::class, 'history']);
        });
    });

Route::get('/all-cars', [CarController::class, 'index']);
Route::middleware('auth:sanctum')->post('/add-car', [CarController::class, 'store']);

// Route::get('/values/{categorySlug?}', [CategoryFieldsController::class, 'index']);


Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [UserController::class, 'logout']);
    //verify otp
    Route::post('/verify-otp', [UserController::class, 'verifyOtp']);
    Route::get('/get-profile', [UserController::class, 'getUserProfile']);
    Route::put('/edit-profile', [UserController::class, 'editProfile']);
    Route::get('/my-ads', [UserController::class, 'myAds']);
    Route::get('/my-packages', [UserController::class, 'myPackages']);
    Route::get('/my-plans', [UserController::class, 'myPlans']);
    Route::post('/create-agent-code', [UserController::class, 'storeAgent']);
    Route::get('/all-clients', [UserController::class, 'allClients']);
    Route::post('/set-rank-one', [UserController::class, 'SetRankOne']);
    Route::patch('/payment/{id}', [UserController::class, 'payment']);

    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/favorite', [FavoriteController::class, 'toggle']);

    // FCM Token Management
    Route::post('/fcm-token', [UserController::class, 'updateFcmToken']);
    Route::delete('/fcm-token', [UserController::class, 'deleteFcmToken']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/status', [NotificationController::class, 'status']);
    Route::post('/notifications', [NotificationController::class, 'store'])->middleware('admin');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/read', [NotificationController::class, 'read']);
    Route::post('/plan-subscriptions', [SubscriptionController::class, 'subscribe']);
    Route::get('/my-subscription', [SubscriptionController::class, 'mySubscription']);

    // Reporting Routes
    Route::post('/listings/{listing}/report', [ListingReportController::class, 'store']);

    // Chat Routes
    Route::prefix('chat')->group(function () {
        Route::get('/inbox', [ChatController::class, 'inbox']);
        Route::get('/unread-count', [ChatController::class, 'unreadCount']);
        Route::post('/send', [ChatController::class, 'send']);
        Route::get('/support', [ChatController::class, 'supportHistory']);
        Route::post('/support', [ChatController::class, 'sendToSupport']);
        Route::get('/listing-summary/{categorySlug}/{listingId}', [ChatController::class, 'getListingSummary']);
        Route::get('/{user}', [ChatController::class, 'history']);
        Route::patch('/{conversationId}/read', [ChatController::class, 'markAsRead']);
    });

    Route::post('/listings/{listing}/renew', [ListingController::class, 'renew']);
});
Route::post('/settings/notifications', [UserController::class, 'updateNotificationSettings']);

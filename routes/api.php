<?php

use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\categoryController;
use App\Http\Controllers\Admin\DashboardAccountController;
use App\Http\Controllers\Admin\DashboardAuthController;
use App\Http\Controllers\Admin\DashboardFilterListsController;
use App\Http\Controllers\Admin\DashboardListingController;
use App\Http\Controllers\Admin\DashboardListingManagementController;
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
Route::post('admin/auth/login', [DashboardAuthController::class, 'login']);
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
    ->middleware(['auth:sanctum', 'dashboard.access'])
    ->group(function () {
        Route::post('auth/logout', [DashboardAuthController::class, 'logout']);
        Route::get('me', [DashboardAccountController::class, 'me']);

        Route::middleware('dashboard.page:account.self')->group(function () {
            Route::post('account/profile', [DashboardAccountController::class, 'updateProfile']);
            Route::put('account/password', [DashboardAccountController::class, 'updatePassword']);
            Route::put('/account/change-password', [AuthController::class, 'changeOwnPassword']);
        });

        Route::middleware('dashboard.page:dashboard.home')->group(function () {
            Route::get('stats', [StatsController::class, 'index']);
            Route::get('recent-activities', [StatsController::class, 'recentActivities']);
        });

        Route::middleware('dashboard.page:settings.index,ads.packages,categories.index,ads.moderation,ads.unpaid')->group(function () {
            Route::get('system-settings', [SystemSettingController::class, 'index']);
        });

        Route::middleware('dashboard.page:users.index,notifications.index,messages.index')->group(function () {
            Route::get('users-summary', [StatsController::class, 'usersSummary']);
        });

        Route::middleware('dashboard.page:ads.moderation')->group(function () {
            Route::get('pending-listings', [StatsController::class, 'pendingListings']);
            Route::get('rejected-listings', [StatsController::class, 'rejectedListings']);
            Route::patch('listings/{listing}/reject', [StatsController::class, 'rejectListing']);
            Route::patch('/listings/{listing}/reopen', [StatsController::class, 'reopen']);
            Route::get('listing-reports', [ListingReportController::class, 'index']);
            Route::post('listing-reports/{listing}/accept', [ListingReportController::class, 'acceptReport']);
            Route::post('listing-reports/{listing}/dismiss', [ListingReportController::class, 'dismissReport']);
            Route::patch('listing-reports/{id}/read', [ListingReportController::class, 'markAsRead']);
            Route::delete('listing-reports/{report}', [ListingReportController::class, 'destroy']);
        });

        Route::middleware('dashboard.page:ads.unpaid')->group(function () {
            Route::get('ads-not-payment', [StatsController::class, 'adsNOTPayment']);
            Route::patch('listings/{listing}/accept-not-payment', [StatsController::class, 'AcceptAdsNotPayment']);
        });

        Route::middleware('dashboard.page:ads.list')->group(function () {
            Route::get('published-listings', [StatsController::class, 'publishedListings']);
        });

        Route::middleware('dashboard.page:ads.create')->group(function () {
            Route::post('listings/create/{section}', [DashboardListingController::class, 'store']);
        });

        Route::get('listings/{listing}', [DashboardListingManagementController::class, 'show']);
        Route::patch('listings/{listing}', [DashboardListingManagementController::class, 'update']);
        Route::post('listings/{listing}/update', [DashboardListingManagementController::class, 'update']);
        Route::delete('listings/{listing}', [DashboardListingManagementController::class, 'destroy']);

        Route::middleware('dashboard.page:ads.moderation,ads.unpaid')->group(function () {
            Route::patch('listings/{listing}/approve', [StatsController::class, 'approveListing']);
        });

        Route::middleware('dashboard.page:reports.index')->group(function () {
            Route::get('transactions', [TransactionsController::class, 'index']);
        });

        Route::middleware('dashboard.page:users.index')->group(function () {
            Route::get('users/{user}', [UserController::class, 'showUserWithListings']);
            Route::put('users/{user}', [UserController::class, 'updateUser']);
            Route::post('users', [UserController::class, 'storeUser']);
            Route::delete('users/{user}', [UserController::class, 'deleteUser']);
            Route::patch('users/{user}/block', [UserController::class, 'blockedUser']);
            Route::get('/featured/{userId}', [BestAdvertiserController::class, 'show']);
            Route::post('/featured', [BestAdvertiserController::class, 'store']);
            Route::put('/disable/{bestAdvertiser}', [BestAdvertiserController::class, 'disable']);
            Route::put('/change-password/{user}', [AuthController::class, 'changePass']);
            Route::get('delegates/{user}/clients', [UserController::class, 'delegateClients']);
            Route::post('/create-otp/{user}', [UserController::class, 'createOtp']);
            Route::post('/user-packages', [PackagesController::class, 'storeOrUpdate']);
            Route::get('/packages', [PackagesController::class, 'index']);
            Route::get('/users/{user}/package', [PackagesController::class, 'getUserPackage']);
            Route::get('user-subscriptions', [UserSubscriptionsController::class, 'index']);
            Route::get('user-subscriptions/{id}', [UserSubscriptionsController::class, 'show']);
            Route::post('user-subscriptions', [UserSubscriptionsController::class, 'store']);
            Route::patch('user-subscriptions/{id}', [UserSubscriptionsController::class, 'update']);
            Route::delete('user-subscriptions/{id}', [UserSubscriptionsController::class, 'destroy']);
            Route::post('user-subscriptions/{id}/add-ads', [UserSubscriptionsController::class, 'addAds']);
        });

        Route::middleware('dashboard.page:categories.index,categories.homepage,categories.banners,categories.images,categories.filters')->group(function () {
            Route::get('categories/usage-report', [categoryController::class, 'usageReport']);
            Route::post('categories', [CategoryController::class, 'store']);
            Route::put('categories/{category}', [CategoryController::class, 'update']);
            Route::delete('categories/{category}', [CategoryController::class, 'destroy']);
            Route::get('categories', [categoryController::class, 'index']);
            Route::get('categories/{category}', [categoryController::class, 'show']);
            Route::put('categories/{category}/toggle-global-image', [categoryController::class, 'toggleGlobalImage']);
            Route::post('categories/{category}/upload-global-image', [categoryController::class, 'uploadGlobalImage'])
                ->middleware('throttle:10,1');
            Route::delete('categories/{category}/global-image', [categoryController::class, 'deleteGlobalImage']);
            Route::post('categories/{slug}/options/ranks', [CategoryController::class, 'updateOptionRanks']);
        });

        Route::middleware('dashboard.page:categories.filters')->group(function () {
            Route::get('filter-lists/governorates', [DashboardFilterListsController::class, 'governorates']);
            Route::get('filter-lists/sections', [DashboardFilterListsController::class, 'sections']);
            Route::get('filter-lists/sections/{mainSection}/sub-sections', [DashboardFilterListsController::class, 'subSections']);
            Route::get('filter-lists/field-category', [DashboardFilterListsController::class, 'fieldCategory']);
            Route::get('category-fields', [CategoryFieldsController::class, 'index']);
            Route::post('category-fields', [CategoryFieldsController::class, 'store']);
            Route::post('category-fields/{categoryField}', [CategoryFieldsController::class, 'update']);
            Route::delete('category-fields/{categoryField}', [CategoryFieldsController::class, 'destroy']);
            Route::post('governorates/ranks', [GovernorateController::class, 'updateGovRanks']);
            Route::post('cities/ranks', [GovernorateController::class, 'updateCityRanks']);
            Route::post('governorates', [GovernorateController::class, 'storeGov']);
            Route::post('/city/{governorate}', [GovernorateController::class, 'storCities']);
            Route::put('governorates/{governorate}', [GovernorateController::class, 'updateGov']);
            Route::patch('governorates/{governorate}/visibility', [GovernorateController::class, 'setGovernorateVisibility']);
            Route::delete('governorates/{governorate}', [GovernorateController::class, 'destroyGov']);
            Route::put('cities/{city}', [GovernorateController::class, 'updateCity']);
            Route::patch('cities/{city}/visibility', [GovernorateController::class, 'setCityVisibility']);
            Route::delete('cities/{city}', [GovernorateController::class, 'deleteCity']);
            Route::get('cities/mappings', [GovernorateController::class, 'getCitiesMappings']);
            Route::post('makes', [MakeController::class, 'addMake']);
            Route::put('makes/{make}', [MakeController::class, 'update']);
            Route::patch('makes/{make}/visibility', [MakeController::class, 'setVisibility']);
            Route::delete('makes/{make}', [MakeController::class, 'destroy']);
            Route::post('makes/{make}/models', [MakeController::class, 'addModel']);
            Route::put('models/{model}', [MakeController::class, 'updateModel']);
            Route::patch('models/{model}/visibility', [MakeController::class, 'setModelVisibility']);
            Route::delete('models/{model}', [MakeController::class, 'deleteModel']);
            Route::post('/main-section/{categorySlug}', [CategorySectionsController::class, 'storeMain']);
            Route::post('/sub-section/{mainSection}', [CategorySectionsController::class, 'addSubSections']);
            Route::put('/main-section/{mainSection}', [CategorySectionsController::class, 'updateMain']);
            Route::put('/sub-section/{subSection}', [CategorySectionsController::class, 'updateSub']);
            Route::patch('/main-section/{mainSection}/visibility', [CategorySectionsController::class, 'setMainVisibility']);
            Route::patch('/sub-section/{subSection}/visibility', [CategorySectionsController::class, 'setSubVisibility']);
            Route::delete('/main-section/{mainSection}', [CategorySectionsController::class, 'destroyMain']);
            Route::delete('/sub-section/{subSection}', [CategorySectionsController::class, 'destroySub']);
            Route::post('/category-sections/main/ranks', [CategorySectionsController::class, 'updateMainRanks']);
            Route::post('/category-sections/sub/ranks', [CategorySectionsController::class, 'updateSubRanks']);
        });

        Route::middleware('dashboard.page:categories.filters,categories.index,ads.create')->group(function () {
            Route::get('filter-lists/automotive', [DashboardFilterListsController::class, 'automotive']);
            Route::get('governorates', [GovernorateController::class, 'index']);
            Route::get('governorates/{governorate}', [GovernorateController::class, 'showGov']);
            Route::get('governorates/{governorate}/cities', [GovernorateController::class, 'cities']);
            Route::get('makes', [MakeController::class, 'index']);
            Route::get('makes/{make}', [MakeController::class, 'show']);
            Route::get('makes/{make}/models', [MakeController::class, 'models']);
            Route::get('main-sections', [CategorySectionsController::class, 'index']);
            Route::get('sub-section/{mainSection}', [CategorySectionsController::class, 'subSections']);
        });

        Route::middleware('dashboard.page:ads.packages')->group(function () {
            Route::get('category-plan-prices', [CategoryPlanPricesController::class, 'index']);
            Route::post('category-plan-prices', [CategoryPlanPricesController::class, 'store']);
        });

        Route::middleware('dashboard.page:categories.banners')->group(function () {
            Route::get('banners', [BannerController::class, 'index']);
            Route::post('banners', [BannerController::class, 'store']);
            Route::put('banners/{slug}', [BannerController::class, 'update']);
        });

        Route::middleware('dashboard.page:notifications.index')->group(function () {
            Route::get('admin-notifications', [AdminNotificationController::class, 'index']);
            Route::post('admin-notifications', [AdminNotificationController::class, 'store']);
            Route::get('admin-notifications/count', [AdminNotificationController::class, 'unreadCount']);
            Route::patch('admin-notifications/{id}/read', [AdminNotificationController::class, 'markAsRead']);
        });

        Route::middleware('dashboard.page:messages.index')->group(function () {
            Route::prefix('support')->group(function () {
                Route::get('/inbox', [AdminChatController::class, 'supportInbox']);
                Route::get('/stats', [AdminChatController::class, 'stats']);
                Route::post('/reply', [AdminChatController::class, 'reply']);
                Route::get('/{user}', [AdminChatController::class, 'supportHistory']);
                Route::patch('/{user}/read', [AdminChatController::class, 'markAsRead']);
            });

            Route::prefix('broadcast')->group(function () {
                Route::post('/', [BroadcastController::class, 'send']);
                Route::post('/group', [BroadcastController::class, 'sendToGroup']);
                Route::post('/preview', [BroadcastController::class, 'preview']);
                Route::get('/history', [BroadcastController::class, 'history']);
            });
        });

        Route::prefix('monitoring')
            ->middleware(['dashboard.page:customer-chats.index', 'can.monitor.chat'])
            ->group(function () {
                Route::get('/conversations', [MonitoringController::class, 'index']);
                Route::get('/conversations/{conversationId}', [MonitoringController::class, 'show']);
                Route::get('/search', [MonitoringController::class, 'search']);
                Route::get('/stats', [MonitoringController::class, 'stats']);
            });

        Route::middleware('dashboard.page:settings.index')->group(function () {
            Route::match(['post', 'put'], '/system-settings/upload-image', [SystemSettingController::class, 'uploadDefaultImage']);
            Route::post('/system-settings', [SystemSettingController::class, 'store']);
        });

        // Backup Routes
        Route::prefix('backups')->middleware('admin')->group(function () {
            Route::get('/diagnostics', [\App\Http\Controllers\Admin\BackupController::class, 'diagnostics']);
            Route::get('/history', [\App\Http\Controllers\Admin\BackupController::class, 'history']);
            Route::get('/', [\App\Http\Controllers\Admin\BackupController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Admin\BackupController::class, 'store']);
            Route::post('/upload', [\App\Http\Controllers\Admin\BackupController::class, 'upload']);
            Route::get('/{id}/download', [\App\Http\Controllers\Admin\BackupController::class, 'download']);
            Route::post('/{id}/restore', [\App\Http\Controllers\Admin\BackupController::class, 'restore']);
            Route::delete('/{id}', [\App\Http\Controllers\Admin\BackupController::class, 'destroy']);
        });
    });

Route::get('/all-cars', [CarController::class, 'index']);
Route::middleware('auth:sanctum')->post('/add-car', [CarController::class, 'store']);

// Route::get('/values/{categorySlug?}', [CategoryFieldsController::class, 'index']);


Route::post('/guest/fcm-token', [UserController::class, 'updateFcmToken']);
Route::get('/guest/fcm-token', [UserController::class, 'getGuestUser']);
//Route::delete('/fcm-token', [UserController::class, 'deleteFcmToken']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [UserController::class, 'logout']);
    Route::delete('/delete-account', [UserController::class, 'deleteMyAccount']);
    //verify otp
    Route::post('/verify-otp', [UserController::class, 'verifyOtp']);
    Route::get('/get-profile', [UserController::class, 'getUserProfile']);
    Route::put('/edit-profile', [UserController::class, 'editProfile']);
    Route::get('/saved-locations', [UserController::class, 'savedLocations']);
    Route::post('/saved-locations', [UserController::class, 'storeSavedLocation']);
    Route::put('/saved-locations/{savedLocation}', [UserController::class, 'updateSavedLocation']);
    Route::delete('/saved-locations/{savedLocation}', [UserController::class, 'deleteSavedLocation']);
    Route::get('/my-ads', [UserController::class, 'myAds']);
    Route::get('/my-packages', [UserController::class, 'myPackages']);
    Route::get('/my-plans', [UserController::class, 'myPlans']);
    Route::post('/create-agent-code', [UserController::class, 'storeAgent']);
    Route::get('/all-clients', [UserController::class, 'allClients']);
    Route::post('/set-rank-one', [UserController::class, 'SetRankOne']);
    Route::patch('/payment/{id}', [UserController::class, 'payment']);

    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/favorite', [FavoriteController::class, 'toggle']);

    // FCM Token Management (authenticated users)
    Route::post('/fcm-token', [UserController::class, 'updateUserFcmToken']);
    Route::delete('/fcm-token', [UserController::class, 'deleteUserFcmToken']);

    Route::get('/notifications/status', [NotificationController::class, 'status'])->middleware('chat.user');
    Route::post('/notifications', [NotificationController::class, 'store'])->middleware('admin');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->middleware('chat.user');
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead'])->middleware('chat.user');
    Route::post('/notifications/read', [NotificationController::class, 'read'])->middleware('chat.user');
    Route::post('/plan-subscriptions', [SubscriptionController::class, 'subscribe']);
    Route::get('/my-subscription', [SubscriptionController::class, 'mySubscription']);

    // Reporting Routes
    Route::post('/listings/{listing}/report', [ListingReportController::class, 'store']);

    Route::post('/listings/{listing}/renew', [ListingController::class, 'renew']);
});
    Route::get('/notifications', [NotificationController::class, 'index'])->middleware('chat.user');

// Chat Routes — accessible by both authenticated users and guests (via guest_uuid header)
Route::prefix('chat')->middleware('chat.user')->group(function () {
    Route::get('/inbox', [ChatController::class, 'inbox']);
    Route::get('/unread-count', [ChatController::class, 'unreadCount']);
    Route::post('/send', [ChatController::class, 'send']);
    Route::get('/support', [ChatController::class, 'supportHistory']);
    Route::post('/support', [ChatController::class, 'sendToSupport']);
    Route::get('/listing-summary/{categorySlug}/{listingId}', [ChatController::class, 'getListingSummary']);
    Route::get('/{user}', [ChatController::class, 'history']);
    Route::patch('/{conversationId}/read', [ChatController::class, 'markAsRead']);
});

Route::post('/settings/notifications', [UserController::class, 'updateNotificationSettings']);

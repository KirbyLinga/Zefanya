refs = [
('mobile/lib/screens/auth/registration_screen.dart',[252,326,390,699,714,762,783,830,846,861,889,898,1216,1233]),
('mobile/lib/main.dart',[290,309,397]),
('mobile/lib/state/cart_state.dart',[197,602,641]),
('mobile/lib/widgets/cart_button.dart',[21,70]),
('mobile/lib/config/pricing.dart',[157,191]),
('mobile/lib/data/repositories/order_repository.dart',[252]),
('mobile/lib/screens/buyer/order_detail_screen.dart',[177,539]),
('mobile/lib/screens/buyer/order_detail_screen.dart',[66]),
('mobile/lib/state/order_state.dart',[260,265]),
('mobile/lib/screens/courier/delivery_tracker_screen.dart',[486,804,986,1051]),
('mobile/lib/utils/image_picker_helper.dart',[27]),
('mobile/test/address_book_test.dart',[300]),
('mobile/test/returns_test.dart',[192,221,280]),
('mobile/test/review_flow_test.dart',[120,286,306]),
('mobile/test/review_limits_test.dart',[99,189]),
('mobile/test/order_lifecycle_test.dart',[44,56,67,99,115]),
('mobile/test/workbook_rider_phase4_test.dart',[424,461,479]),
('mobile/test/workbook_buyer_phase3_test.dart',[512,557,575,588,627,657,781]),
('mobile/test/buyer_bm_quick_wins_test.dart',[393,497,540]),
('mobile/test/search_filter_test.dart',[50]),
('mobile/test/product_sort_test.dart',[27,32]),
('mobile/test/task5_cart_nav_test.dart',[39]),
('mobile/test/quantity_limit_test.dart',[25,45]),
('mobile/test/product_variants_test.dart',[111,145,173,219]),
('mobile/test/catalog_state_test.dart',[140,152]),
('mobile/test/catalog_wiring_test.dart',[177,190]),
('mobile/test/store_follow_test.dart',[65,103,121]),
('mobile/test/otp_flow_test.dart',[552,613]),
('mobile/test/auth_flow_test.dart',[129,146]),
('web/tests/Feature/Api/BuyerRegistrationApiTest.php',[207]),
('web/tests/Feature/Api/BuyerOtpApiTest.php',[106]),
('web/tests/Feature/Api/MilestoneApiTest.php',[126]),
('web/tests/Feature/Api/CourierLoginApiTest.php',[138]),
('web/tests/Feature/Api/CourierJobApiTest.php',[108]),
('web/app/Http/Requests/Buyer/StoreBuyerRegistrationRequest.php',[25,28]),
('web/config/database.php',[57]),
]
for path, lines in refs:
    try:
        data=open(path,encoding='utf-8').read().splitlines()
    except Exception as e:
        print('MISSING',path,e); continue
    for n in lines:
        print(f'{path}:{n}: {data[n-1].strip() if n<=len(data) else "OUT OF RANGE"}')

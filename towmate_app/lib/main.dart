import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'core/app_prefs.dart';
import 'core/route_observer.dart';
import 'core/theme.dart';
import 'models/booking_model.dart';
import 'screens/customer/about_screen.dart';
import 'screens/customer/book_now_screen.dart';
import 'screens/customer/booking_detail_screen.dart';
import 'screens/customer/booking_success_screen.dart';
import 'screens/customer/customer_quotation_screen.dart';
import 'screens/customer/customer_services_screen.dart';
import 'screens/customer/customer_vehicle_types_screen.dart';
import 'screens/customer/edit_profile_screen.dart';
import 'screens/customer/home_screen.dart';
import 'screens/customer/login_screen.dart';
import 'screens/customer/my_bookings_screen.dart';
import 'screens/customer/notifications_screen.dart';
import 'screens/customer/profile_screen.dart';
import 'screens/customer/public_home_screen.dart';
import 'screens/customer/services_screen.dart';
import 'screens/customer/signup_screen.dart';
import 'screens/team_leader/tl_active_task_shell.dart';
import 'screens/team_leader/tl_force_password_screen.dart';
import 'screens/team_leader/tl_history_screen.dart';
import 'screens/team_leader/tl_home_screen.dart';
import 'screens/team_leader/tl_profile_screen.dart';
import 'services/api_service.dart';
import 'services/tl_presence_controller.dart';

final themeModeNotifier = AppPrefs.themeModeNotifier;

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  FlutterError.onError = (details) {
    if (kDebugMode) FlutterError.dumpErrorToConsole(details);
  };
  runApp(const MyApp());
}

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<ThemeMode>(
      valueListenable: themeModeNotifier,
      builder: (_, mode, __) => MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: AppTheme.light,
        darkTheme: AppTheme.dark,
        themeMode: mode,
        home: const _AuthGate(),
        navigatorObservers: [appRouteObserver],
        onGenerateRoute: (settings) {
          final Widget page;
          if (settings.name == '/booking-detail') {
            final args = settings.arguments;
            if (args is String && args.isNotEmpty) {
              page = BookingDetailScreen(bookingCode: args);
            } else if (args is Map &&
                args['bookingCode'] is String &&
                (args['bookingCode'] as String).isNotEmpty) {
              page = BookingDetailScreen(
                bookingCode: args['bookingCode'] as String,
                asGroupOverview: args['asGroupOverview'] == true,
              );
            } else {
              page = const MyBookingsScreen();
            }
          } else if (settings.name == '/booking-success') {
            final bookings = settings.arguments;
            page = bookings is List<BookingGroupSibling> && bookings.isNotEmpty
                ? BookingSuccessScreen(bookings: bookings)
                : const HomeScreen();
          } else {
            page = switch (settings.name) {
              '/public-home' => const PublicHomeScreen(),
              '/login' => const LoginScreen(),
              '/signup' => const SignupScreen(),
              '/home' => const HomeScreen(),
              '/book-now' => const BookNowScreen(),
              '/my-bookings' => const MyBookingsScreen(),
              '/quotation' => const CustomerQuotationScreen(),
              '/services' => const ServicesScreen(),
              '/customer-services' => const CustomerServicesScreen(),
              '/vehicle-types' => const CustomerVehicleTypesScreen(),
              '/about' => const AboutScreen(),
              '/tl-force-password' => const TlForcePasswordScreen(),
              '/tl-home' => const TlHomeScreen(),
              '/tl-active-task' => const TlActiveTaskShell(),
              '/tl-history' => const TlHistoryScreen(),
              '/tl-profile' => const TlProfileScreen(),
              '/profile' => const ProfileScreen(),
              '/edit-profile' => const EditProfileScreen(),
              '/notifications' => const NotificationsScreen(),
              _ => const PublicHomeScreen(),
            };
          }

          return PageRouteBuilder(
            settings: settings,
            pageBuilder: (_, _, _) => page,
            transitionsBuilder: (_, animation, _, child) => FadeTransition(
              opacity: CurvedAnimation(
                parent: animation,
                curve: Curves.easeOut,
              ),
              child: child,
            ),
            transitionDuration: const Duration(milliseconds: 250),
          );
        },
      ),
    );
  }
}

class _AuthGate extends StatefulWidget {
  const _AuthGate();

  @override
  State<_AuthGate> createState() => _AuthGateState();
}

class _AuthGateState extends State<_AuthGate> {
  @override
  void initState() {
    super.initState();
    _checkSession();
  }

  Future<void> _checkSession() async {
    final loggedIn = await ApiService.isLoggedIn();
    if (!mounted) return;
    if (!loggedIn) {
      AppPrefs.useGuestTheme();
      Navigator.pushReplacementNamed(context, '/public-home');
      return;
    }

    await AppPrefs.restoreAuthenticatedTheme();
    final role = await ApiService.getUserRole();
    final mustChange = await ApiService.getMustChangePassword();
    if (!mounted) return;

    if (role == 'Team Leader') {
      TlPresenceController.start();
      Navigator.pushReplacementNamed(
        context,
        mustChange ? '/tl-force-password' : '/tl-home',
      );
    } else if (role != null) {
      Navigator.pushReplacementNamed(context, '/home');
    } else {
      await ApiService.clearSession();
      AppPrefs.useGuestTheme();
      Navigator.pushReplacementNamed(context, '/public-home');
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(backgroundColor: context.bg);
}

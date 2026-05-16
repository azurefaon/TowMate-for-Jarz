import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'core/theme.dart';
import 'screens/customer/public_home_screen.dart';
import 'screens/customer/login_screen.dart';
import 'screens/customer/signup_screen.dart';
import 'screens/customer/home_screen.dart';
import 'screens/customer/services_screen.dart';
import 'screens/customer/about_screen.dart';
import 'screens/customer/book_now_screen.dart';
import 'screens/customer/my_bookings_screen.dart';
import 'screens/customer/customer_quotation_screen.dart';
import 'screens/customer/booking_detail_screen.dart';
import 'screens/customer/profile_screen.dart';
import 'screens/team_leader/tl_force_password_screen.dart';
import 'screens/team_leader/tl_home_screen.dart';
import 'screens/team_leader/tl_active_task_shell.dart';
import 'services/api_service.dart';

void main() {
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
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: AppTheme.data,
      home: const _AuthGate(),
      onGenerateRoute: (settings) {
        final Widget page;
        if (settings.name == '/booking-detail') {
          page = BookingDetailScreen(
              bookingCode: settings.arguments as String);
        } else {
          page = switch (settings.name) {
            '/public-home'        => const PublicHomeScreen(),
            '/login'              => const LoginScreen(),
            '/signup'             => const SignupScreen(),
            '/home'               => const HomeScreen(),
            '/book-now'           => const BookNowScreen(),
            '/my-bookings'        => const MyBookingsScreen(),
            '/quotation'          => const CustomerQuotationScreen(),
            '/services'           => const ServicesScreen(),
            '/about'              => const AboutScreen(),
            '/tl-force-password'  => const TlForcePasswordScreen(),
            '/tl-home'            => const TlHomeScreen(),
            '/tl-active-task'     => const TlActiveTaskShell(),
            '/profile'            => const ProfileScreen(),
            _                     => const PublicHomeScreen(),
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
    );
  }
}

// Shown on cold-start. Immediately replaces itself with /home if a token exists.
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
      Navigator.pushReplacementNamed(context, '/public-home');
      return;
    }

    final role = await ApiService.getUserRole();
    final mustChange = await ApiService.getMustChangePassword();
    if (!mounted) return;

    if (role == 'Team Leader') {
      Navigator.pushReplacementNamed(
          context, mustChange ? '/tl-force-password' : '/tl-home');
    } else if (role != null) {
      Navigator.pushReplacementNamed(context, '/home');
    } else {
      await ApiService.clearSession();
      Navigator.pushReplacementNamed(context, '/public-home');
    }
  }

  @override
  Widget build(BuildContext context) =>
      const Scaffold(backgroundColor: Colors.white);
}

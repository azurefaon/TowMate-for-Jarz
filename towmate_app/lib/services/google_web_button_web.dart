import 'package:flutter/widgets.dart';
import 'package:google_sign_in_web/web_only.dart' as web;
import 'google_web_button_types.dart';

Widget renderGoogleWebButton({
  double? minimumWidth,
  GoogleButtonText text = GoogleButtonText.continueWith,
  bool iconOnly = false,
}) {
  return web.renderButton(
    configuration: web.GSIButtonConfiguration(
      type: iconOnly ? web.GSIButtonType.icon : null,
      theme: web.GSIButtonTheme.outline,
      shape: web.GSIButtonShape.rectangular,
      size: web.GSIButtonSize.large,
      text: text == GoogleButtonText.signUp
          ? web.GSIButtonText.signupWith
          : web.GSIButtonText.continueWith,
      logoAlignment: web.GSIButtonLogoAlignment.left,
      minimumWidth: iconOnly ? null : minimumWidth,
    ),
  );
}

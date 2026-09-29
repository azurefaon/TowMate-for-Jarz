import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';

class LegalSection {
  const LegalSection({required this.heading, required this.body});

  final String heading;
  final String body;
}

typedef ParsedLegalDocument = ({String intro, List<LegalSection> sections});

ParsedLegalDocument parseLegalDocument(String raw) {
  final lines = raw.replaceAll('\r\n', '\n').split('\n');
  final introLines = <String>[];
  final sections = <LegalSection>[];
  String? currentHeading;
  final currentBody = <String>[];

  void flushSection() {
    final heading = currentHeading;
    if (heading != null) {
      sections.add(
        LegalSection(heading: heading, body: currentBody.join('\n').trim()),
      );
    }
    currentBody.clear();
  }

  for (final line in lines) {
    if (line.trimLeft().startsWith('##')) {
      flushSection();
      currentHeading = line.trimLeft().replaceFirst(RegExp(r'^#+\s*'), '');
    } else if (currentHeading == null) {
      introLines.add(line);
    } else {
      currentBody.add(line);
    }
  }
  flushSection();

  return (intro: introLines.join('\n').trim(), sections: sections);
}

class LegalDocumentScreen extends StatelessWidget {
  const LegalDocumentScreen({
    super.key,
    required this.title,
    required this.versionLabel,
    required this.intro,
    required this.sections,
  });

  final String title;
  final String versionLabel;
  final String intro;
  final List<LegalSection> sections;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.bg,
      appBar: AppBar(
        backgroundColor: context.bg,
        elevation: 0,
        foregroundColor: context.textPrimary,
        title: Text(
          title,
          style: GoogleFonts.inter(
            color: context.textPrimary,
            fontSize: 17,
            fontWeight: FontWeight.w600,
            letterSpacing: -0.2,
          ),
        ),
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 8, 24, 32),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                versionLabel,
                style: GoogleFonts.inter(
                  color: context.textSecondary,
                  fontSize: 12,
                  fontWeight: FontWeight.w500,
                  letterSpacing: 0.2,
                ),
              ),
              const SizedBox(height: 16),
              Text(
                intro,
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 14,
                  height: 1.55,
                  letterSpacing: 0.1,
                ),
              ),
              for (final section in sections) ...[
                const SizedBox(height: 24),
                Text(
                  section.heading,
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                    letterSpacing: -0.1,
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  section.body,
                  style: GoogleFonts.inter(
                    color: context.textSecondary,
                    fontSize: 13.5,
                    height: 1.6,
                    letterSpacing: 0.1,
                  ),
                ),
              ],
              const SizedBox(height: 32),
              Text(
                'Questions about this document? Contact TowMate support through the app.',
                style: GoogleFonts.inter(
                  color: context.textTertiary,
                  fontSize: 12,
                  height: 1.5,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// lib/utils/chat_validation.dart
//
// Chat message and attachment rules, kept out of the screen so they can be
// tested without pumping a widget and so the repository can reject the same
// shapes if it ever accepts uploads.

/// The longest message body the composer accepts, in characters.
const int kChatMaxMessageLength = 1000;

/// The largest attachment the composer accepts.
const int kChatMaxAttachmentBytes = 5 * 1024 * 1024;

const Set<String> kChatAllowedAttachmentExtensions = {'jpg', 'jpeg', 'png', 'pdf'};

/// A file the buyer picked, before it is sent.
class ChatAttachment {
  const ChatAttachment({
    required this.name,
    required this.sizeBytes,
    this.path,
  });

  final String name;
  final int sizeBytes;

  /// Local path on this device. Deliberately not uploaded by the mock
  /// repository; a real transport would replace it with a server key.
  final String? path;

  Map<String, dynamic> toJson() => {
    'name': name,
    'sizeBytes': sizeBytes,
    if (path != null) 'path': path,
  };

  factory ChatAttachment.fromJson(Map<String, dynamic> json) => ChatAttachment(
    name: json['name'] as String? ?? 'Attachment',
    sizeBytes: (json['sizeBytes'] as num?)?.toInt() ?? 0,
    path: json['path'] as String?,
  );
}

enum ChatAttachmentProblem { unsupportedType, tooLarge, empty }

String chatAttachmentProblemMessage(ChatAttachmentProblem problem) =>
    switch (problem) {
      ChatAttachmentProblem.unsupportedType =>
        'Attachments must be JPG, PNG, or PDF.',
      ChatAttachmentProblem.tooLarge => 'Attachments must be 5 MB or smaller.',
      ChatAttachmentProblem.empty => 'That file is empty.',
    };

/// Returns why [attachment] cannot be sent, or null when it is acceptable.
ChatAttachmentProblem? validateChatAttachment(ChatAttachment attachment) {
  if (attachment.sizeBytes <= 0) return ChatAttachmentProblem.empty;
  if (attachment.sizeBytes > kChatMaxAttachmentBytes) {
    return ChatAttachmentProblem.tooLarge;
  }
  final name = attachment.name.trim().toLowerCase();
  final dot = name.lastIndexOf('.');
  final extension = dot < 0 ? '' : name.substring(dot + 1);
  if (!kChatAllowedAttachmentExtensions.contains(extension)) {
    return ChatAttachmentProblem.unsupportedType;
  }
  return null;
}

/// Returns why [text] cannot be sent, or null when it is acceptable.
String? validateChatMessage(String text, {bool hasAttachment = false}) {
  final trimmed = text.trim();
  if (trimmed.isEmpty && !hasAttachment) return 'Type a message first.';
  if (text.length > kChatMaxMessageLength) {
    return 'Messages can be up to $kChatMaxMessageLength characters.';
  }
  return null;
}

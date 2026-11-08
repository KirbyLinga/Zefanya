import 'package:file_selector/file_selector.dart';
import 'package:image_picker/image_picker.dart';
import '../data/api/api_exception.dart';
import '../data/repositories/linehaul_vehicle_repository.dart';

abstract interface class CourierDocumentPicker {
  Future<CourierDocument?> pick({required bool camera});
}

class DeviceCourierDocumentPicker implements CourierDocumentPicker {
  const DeviceCourierDocumentPicker();

  @override
  Future<CourierDocument?> pick({required bool camera}) async {
    final XFile? file = camera
        ? await ImagePicker().pickImage(source: ImageSource.camera)
        : await openFile(
            acceptedTypeGroups: const [
              XTypeGroup(
                label: 'Courier documents',
                extensions: ['jpg', 'jpeg', 'png', 'pdf'],
                mimeTypes: ['image/jpeg', 'image/png', 'application/pdf'],
                uniformTypeIdentifiers: [
                  'public.jpeg',
                  'public.png',
                  'com.adobe.pdf',
                ],
              ),
            ],
          );
    if (file == null) return null;
    final size = await file.length();
    final extension = file.name.split('.').last.toLowerCase();
    if (size <= 0 ||
        size > 5 * 1024 * 1024 ||
        !const ['jpg', 'jpeg', 'png', 'pdf'].contains(extension)) {
      throw const ValidationException(
        fieldErrors: {'document': 'Choose a JPG, PNG or PDF up to 5 MB.'},
      );
    }
    return CourierDocument(file.name, await file.readAsBytes());
  }
}

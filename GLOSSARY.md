# rm-q

rm-q deletes files only after the database changes that orphaned them are committed, and only once a grace period is
over, so a rollback or a restored backup never points at files that are gone.

## Language

**Staged file**:
A file recorded for deletion, identified by a disk and a path on it.
_Avoid_: Queued file, pending deletion

**Disk**:
A configured filesystem disk the staged file lives on. Every staged file has one.
_Avoid_: Absolute path, local path

**Grace period**:
How long a staged file waits before it may be deleted.
_Avoid_: Delay, TTL

**Attempt**:
One try at deleting a staged file. It counts even when the run dies during it.

**Failed deletion**:
A staged file whose last attempt did not delete it, and which keeps its error until a later attempt.
_Avoid_: Error row

**Given-up file**:
A failed deletion that used up its attempts: only a retry brings it back.
_Avoid_: Exhausted file, dead file

**Missing file**:
A staged file absent from storage that is available. It counts as deleted.
_Avoid_: Already deleted

**Unavailable storage**:
Storage whose disk root, or the directory holding the staged file, cannot be found, such as an unmounted volume. A
staged file on it is a failed deletion, never a missing file.
_Avoid_: Missing directory, offline disk

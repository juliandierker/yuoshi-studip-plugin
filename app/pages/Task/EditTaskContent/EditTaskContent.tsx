import React from "react"
import { Link, RouteComponentProps } from "@reach/router"
import { NSTaskAdapter } from "@xyng/yuoshi-backend-adapter"
import { useCurrentTaskContext } from "contexts/CurrentTaskContext"

import EditQuizContent from "./EditQuizContent/EditQuizContent"
import EditDragContent from "./EditDragContent/EditDragContent"
import EditCardContent from "./EditCardContent/EditCardContent"
import { useEditTaskContext } from "./useEditTaskContent"
import TaskTypeName = NSTaskAdapter.TaskTypeName

export type EditTaskContentView<T = {}> = React.FC<
    {
        editTaskContext: ReturnType<typeof useEditTaskContext>
    } & T
>

const RenderTaskViews: React.FC = () => {
    const currentTaskContext = useCurrentTaskContext()
    const editTaskContext = useEditTaskContext(currentTaskContext)

    const { task } = currentTaskContext

    switch (task.getType()) {
        case TaskTypeName.DRAG:
            return <EditDragContent editTaskContext={editTaskContext} />
        case TaskTypeName.CARD:
            return <EditCardContent editTaskContext={editTaskContext} />
        case TaskTypeName.MULTI:
            return <EditQuizContent editTaskContext={editTaskContext} />
        default:
            return null
    }
}

const EditTaskContent: React.FC<RouteComponentProps> = () => {
    return (
        <div>
            <Link to="../../">Zurück</Link>
            <RenderTaskViews />
        </div>
    )
}

export default EditTaskContent
